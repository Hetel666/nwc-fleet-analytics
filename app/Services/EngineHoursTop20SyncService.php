<?php

namespace App\Services;

use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\ProjectWialonGroup;
use App\Models\WialonReportSyncItem;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EngineHoursTop20SyncService
{
    public const LOCK_KEY = 'wialon-engine-hours-top20-sync';

    public function __construct(
        private WialonEngineHoursReportService $reports,
        private WialonEngineHoursReportParser $parser,
        private WialonSessionManager $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function sync(array $filters): array
    {
        $planned = $this->plan($filters);
        $run = $this->run($filters);

        return [
            'planned' => $planned,
            'run' => $run,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function plan(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $force = (bool) ($filters['force'] ?? false);
        $groups = $this->groups($filters);
        $summary = [
            'dates' => 0,
            'groups' => $groups->count(),
            'created' => 0,
            'updated' => 0,
            'existing_skipped' => 0,
            'completed_skipped' => 0,
            'refreshed_incomplete' => 0,
        ];

        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString()) as $date) {
            $summary['dates']++;

            foreach ($groups as $group) {
                $item = WialonReportSyncItem::query()
                    ->where('sync_type', WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20)
                    ->whereDate('report_date', $date->toDateString())
                    ->where('wialon_group_id', $group->wialon_group_id)
                    ->first();

                $refreshIncomplete = false;

                if ($item && $item->status === WialonReportSyncItem::STATUS_COMPLETED && ! $force) {
                    $refreshIncomplete = $this->completedItemNeedsRefresh($item, $group);

                    if (! $refreshIncomplete) {
                        $summary['completed_skipped']++;

                        continue;
                    }

                    $summary['refreshed_incomplete']++;
                }

                if ($item && ! $force) {
                    if ($item->wialon_group_name !== $group->name) {
                        $item->forceFill(['wialon_group_name' => $group->name])->save();
                    }

                    if (! $refreshIncomplete) {
                        $summary['existing_skipped']++;

                        continue;
                    }
                }

                WialonReportSyncItem::query()->updateOrCreate(
                    [
                        'sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
                        'report_date' => $date->toDateString(),
                        'wialon_group_id' => (string) $group->wialon_group_id,
                    ],
                    [
                        'wialon_group_name' => $group->name,
                        'status' => WialonReportSyncItem::STATUS_PENDING,
                        'attempts' => ($force || $refreshIncomplete) ? 0 : (int) ($item?->attempts ?? 0),
                        'rows_received' => ($force || $refreshIncomplete) ? 0 : (int) ($item?->rows_received ?? 0),
                        'rows_saved' => ($force || $refreshIncomplete) ? 0 : (int) ($item?->rows_saved ?? 0),
                        'started_at' => null,
                        'finished_at' => null,
                        'next_retry_at' => null,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'run_id' => null,
                    ]
                );

                $item ? $summary['updated']++ : $summary['created']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function run(array $filters): array
    {
        $lock = Cache::lock(self::LOCK_KEY, (int) config('fleet.wialon.engine_hours_sync_lock_seconds', 900));

        if (! $lock->get()) {
            return ['locked' => true, 'processed' => 0, 'completed' => 0, 'retry' => 0, 'failed' => 0, 'details' => []];
        }

        $summary = ['locked' => false, 'processed' => 0, 'completed' => 0, 'retry' => 0, 'failed' => 0, 'details' => []];
        $runId = (string) Str::uuid();

        try {
            $items = $this->dueItems($filters);

            if ($items->isEmpty()) {
                return $summary;
            }

            $sid = $this->sessions->sid();

            foreach ($items as $item) {
                $result = $this->processItem($item, $sid, $runId, (bool) ($filters['details'] ?? false));
                $summary['processed']++;
                $summary[$result['status']]++;

                if (($filters['details'] ?? false) && isset($result['detail'])) {
                    $summary['details'][] = $result['detail'];
                }
            }

            if ($summary['completed'] > 0) {
                $this->bumpDashboardDataVersion();
            }
        } finally {
            $this->sessions->close();
            optional($lock)->release();
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function diagnose(array $filters): array
    {
        $rows = $this->baseRows($filters)->get();
        $allowed = $this->allowedTypes();
        $source = $rows->firstWhere('engine_hours_source', EngineHoursReportUnitDay::SOURCE);

        return [
            'total_rows' => $rows->count(),
            'valid_rows' => $rows->whereNotNull('engine_hours')->where('engine_hours', '>=', 0)->count(),
            'null_rows' => $rows->whereNull('engine_hours')->count(),
            'invalid_rows' => $rows->where('parse_status', '!=', 'ok')->count(),
            'excluded_vehicle_types' => $rows->filter(fn ($row): bool => ! in_array(FleetVehicleType::slug($row->vehicle_type), $allowed, true))->count(),
            'included_rows' => $this->topRows($filters, 'desc', 100000)->count(),
            'source_metadata' => $source ? [
                'resource_id' => $source->report_resource_id,
                'template_id' => $source->report_template_id,
                'template_name' => $source->report_template_name,
                'source_table' => $source->source_table,
                'engine_hours_column_index' => $source->engine_hours_column_index,
                'engine_hours_column_label' => $source->engine_hours_column_label,
            ] : null,
            'top_most' => $this->topRows($filters, 'desc', 20)->values()->all(),
            'top_least' => $this->topRows($filters, 'asc', 20)->values()->all(),
        ];
    }

    private function processItem(WialonReportSyncItem $item, string $sid, string $runId, bool $details): array
    {
        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_RUNNING,
            'attempts' => (int) $item->attempts + 1,
            'started_at' => now($this->timezone()),
            'finished_at' => null,
            'next_retry_at' => null,
            'run_id' => $runId,
        ])->save();

        try {
            $detail = $this->executeItem($item->refresh(), $sid, $runId, $details);

            return ['status' => 'completed', 'detail' => $detail];
        } catch (Throwable $exception) {
            $status = $this->markRetryOrFailed($item->refresh(), $exception, $runId);

            return ['status' => $status];
        }
    }

    private function executeItem(WialonReportSyncItem $item, string $sid, string $runId, bool $details): array
    {
        $group = ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->where('wialon_group_id', $item->wialon_group_id)
            ->first();

        if (! $group) {
            throw new \RuntimeException('Wialon group is not mapped in project_wialon_groups.');
        }

        $date = CarbonImmutable::parse($item->report_date, $this->timezone());
        $report = $this->reports->executeForGroupWithSession($group, $date->startOfDay(), $date->endOfDay(), $sid);
        $parsed = $this->parser->parse($report);
        $saved = $this->saveRecords($group, $date, $parsed['records'], $report);

        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_COMPLETED,
            'rows_received' => count($parsed['records']),
            'rows_saved' => $saved['saved'],
            'finished_at' => now($this->timezone()),
            'next_retry_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'run_id' => $runId,
        ])->save();

        return [
            'date' => $date->toDateString(),
            'group' => $item->wialon_group_id,
            'name' => $item->wialon_group_name,
            'rows_received' => count($parsed['records']),
            'rows_saved' => $saved['saved'],
            'null_rows' => $saved['null'],
            'invalid_rows' => $saved['invalid'],
            'excluded_vehicle_types' => $saved['excluded_vehicle_types'],
            'missing_unit' => $saved['missing_unit'],
            'missing_samples' => $saved['missing_samples'],
            'tables' => $parsed['tables'] ?? [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{saved: int, null: int, invalid: int, excluded_vehicle_types: int, missing_unit: int, missing_samples: array<int, array<string, mixed>>}
     */
    private function saveRecords(ProjectWialonGroup $group, CarbonImmutable $date, array $records, array $report): array
    {
        $equipment = $this->equipmentByReportKey($group);
        $allowed = $this->allowedTypes();
        $summary = ['saved' => 0, 'null' => 0, 'invalid' => 0, 'excluded_vehicle_types' => 0, 'missing_unit' => 0, 'missing_samples' => []];

        DB::transaction(function () use ($records, $equipment, $allowed, $group, $date, $report, &$summary): void {
            foreach ($records as $record) {
                $item = $this->matchEquipment($equipment, $record);

                if (! $item) {
                    $summary['missing_unit']++;

                    if (count($summary['missing_samples']) < 5) {
                        $summary['missing_samples'][] = [
                            'wialon_unit_id' => $record['wialon_unit_id'] ?? null,
                            'unit_name' => $record['unit_name'] ?? null,
                            'engine_hours' => $record['engine_hours'] ?? null,
                            'raw_value' => $record['raw_value'] ?? null,
                        ];
                    }

                    continue;
                }

                $vehicleType = FleetVehicleType::display($item->type?->name);
                $typeSlug = FleetVehicleType::slug($vehicleType);

                if (! in_array($typeSlug, $allowed, true)) {
                    $summary['excluded_vehicle_types']++;

                    continue;
                }

                if ($record['engine_hours'] === null) {
                    $record['parse_status'] === 'negative_engine_hours' ? $summary['invalid']++ : $summary['null']++;
                }

                $this->saveUnitDay($item, $group, $date, $record, $report, $vehicleType);
                $summary['saved']++;
            }
        });

        return $summary;
    }

    private function saveUnitDay(Equipment $equipment, ProjectWialonGroup $group, CarbonImmutable $date, array $record, array $report, string $vehicleType): void
    {
        $existing = EngineHoursReportUnitDay::query()
            ->where('equipment_id', $equipment->id)
            ->whereDate('stat_date', $date->toDateString())
            ->first();
        $sourceGroups = collect($existing?->source_group_ids_json ?? [])
            ->push((string) $group->wialon_group_id)
            ->unique()
            ->values()
            ->all();

        if ($existing) {
            $existing->forceFill([
                'source_group_ids_json' => $sourceGroups,
                'synced_at' => now($this->timezone()),
            ])->save();

            return;
        }

        EngineHoursReportUnitDay::query()->create([
            'stat_date' => $date->toDateString(),
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'equipment_type_id' => $equipment->equipment_type_id,
            'ownership_type' => $equipment->ownership_type,
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'unit_name' => $record['unit_name'] ?: $equipment->name,
            'vehicle_type' => $vehicleType,
            'engine_hours' => $record['engine_hours'],
            'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
            'parse_status' => $record['parse_status'],
            'report_resource_id' => (string) ($report['resource_id'] ?? ''),
            'report_template_id' => (string) ($report['template_id'] ?? ''),
            'report_template_name' => (string) ($report['template_name'] ?? ''),
            'source_table' => $record['source_table'],
            'engine_hours_column_index' => $record['engine_hours_column_index'],
            'engine_hours_column_label' => $record['engine_hours_column_label'],
            'source_group_ids_json' => $sourceGroups,
            'raw_value_json' => $record['raw_value'],
            'synced_at' => now($this->timezone()),
        ]);
    }

    /**
     * @return array{id: array<string, Equipment>, name: array<string, Equipment>}
     */
    private function equipmentByReportKey(ProjectWialonGroup $group): array
    {
        $items = Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->where('project_id', $group->project_id)
            ->where('ownership_type', $group->ownership_type)
            ->get();

        return [
            'id' => $items->filter(fn (Equipment $item): bool => trim((string) $item->wialon_unit_id) !== '')
                ->keyBy(fn (Equipment $item): string => trim((string) $item->wialon_unit_id))
                ->all(),
            'name' => $items->keyBy(fn (Equipment $item): string => $this->unitKey($item->name))->all(),
        ];
    }

    /**
     * @param  array{id: array<string, Equipment>, name: array<string, Equipment>}  $equipment
     */
    private function matchEquipment(array $equipment, array $record): ?Equipment
    {
        $id = trim((string) ($record['wialon_unit_id'] ?? ''));

        if ($id !== '' && isset($equipment['id'][$id])) {
            return $equipment['id'][$id];
        }

        $name = $this->unitKey((string) ($record['unit_name'] ?? ''));

        return $name === '' ? null : ($equipment['name'][$name] ?? null);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function topRows(array $filters, string $direction, int $limit = 20): Collection
    {
        return $this->baseRows($filters)
            ->whereNotNull('engine_hours_report_unit_days.engine_hours')
            ->where('engine_hours_report_unit_days.engine_hours', '>=', 0)
            ->where('engine_hours_report_unit_days.engine_hours_source', EngineHoursReportUnitDay::SOURCE)
            ->where('engine_hours_report_unit_days.parse_status', 'ok')
            ->orderBy('engine_hours_report_unit_days.engine_hours', $direction)
            ->orderBy('engine_hours_report_unit_days.stat_date')
            ->orderBy('engine_hours_report_unit_days.unit_name')
            ->orderBy('engine_hours_report_unit_days.wialon_unit_id')
            ->limit($limit)
            ->get();
    }

    private function baseRows(array $filters): Builder
    {
        [$from, $to] = $this->period($filters);
        $ownership = $this->ownership($filters['ownership_type'] ?? $filters['ownership'] ?? null);

        return EngineHoursReportUnitDay::query()
            ->join('equipments', 'equipments.id', '=', 'engine_hours_report_unit_days.equipment_id')
            ->leftJoin('equipment_types', 'equipment_types.id', '=', 'engine_hours_report_unit_days.equipment_type_id')
            ->leftJoin('projects', 'projects.id', '=', 'engine_hours_report_unit_days.project_id')
            ->whereDate('engine_hours_report_unit_days.stat_date', '>=', $from->toDateString())
            ->whereDate('engine_hours_report_unit_days.stat_date', '<=', $to->toDateString())
            ->where('equipments.active', true)
            ->whereIn('engine_hours_report_unit_days.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->whereIn('engine_hours_report_unit_days.vehicle_type', FleetVehicleType::names(FleetVehicleType::TOP_WORKING_TYPES))
            ->when(! empty($filters['project_id']), fn (Builder $query) => $query->where('engine_hours_report_unit_days.project_id', (int) $filters['project_id']))
            ->when(! empty($filters['equipment_type_id']), fn (Builder $query) => $query->where('engine_hours_report_unit_days.equipment_type_id', (int) $filters['equipment_type_id']))
            ->when($ownership, fn (Builder $query) => $query->where('engine_hours_report_unit_days.ownership_type', $ownership))
            ->when(! empty($filters['unit']), function (Builder $query) use ($filters): void {
                $unit = trim((string) $filters['unit']);
                $query->where(function (Builder $query) use ($unit): void {
                    $query->where('engine_hours_report_unit_days.wialon_unit_id', $unit)
                        ->orWhere('engine_hours_report_unit_days.unit_name', 'like', '%'.$unit.'%')
                        ->orWhere('equipments.name', 'like', '%'.$unit.'%');
                });
            })
            ->select([
                'engine_hours_report_unit_days.*',
                'equipments.name as equipment_name',
                'equipments.registration_number',
                'equipment_types.name as equipment_type_name',
                'projects.name as project_name',
            ]);
    }

    private function markRetryOrFailed(WialonReportSyncItem $item, Throwable $exception, string $runId): string
    {
        $temporary = $this->isTemporary($exception);
        $failed = ! $temporary || (int) $item->attempts >= 3;
        $status = $failed ? WialonReportSyncItem::STATUS_FAILED : WialonReportSyncItem::STATUS_RETRY;

        $item->forceFill([
            'status' => $status,
            'finished_at' => now($this->timezone()),
            'next_retry_at' => $failed ? null : now($this->timezone())->addMinutes(match ((int) $item->attempts) {
                1 => 5,
                2 => 15,
                default => 60,
            }),
            'last_error_code' => $this->errorCode($exception),
            'last_error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'run_id' => $runId,
        ])->save();

        return $failed ? 'failed' : 'retry';
    }

    /**
     * @return Collection<int, ProjectWialonGroup>
     */
    private function groups(array $filters): Collection
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when(DB::getSchemaBuilder()->hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when(! empty($filters['group']), fn (Builder $query) => $query->where('wialon_group_id', trim((string) $filters['group'])))
            ->when(! empty($filters['project']), fn (Builder $query) => $query->where('project_id', (int) $filters['project']))
            ->when($this->ownership($filters['ownership'] ?? $filters['ownership_type'] ?? null), fn (Builder $query, string $ownership) => $query->where('ownership_type', $ownership))
            ->orderBy('wialon_group_id')
            ->get();
    }

    private function completedItemNeedsRefresh(WialonReportSyncItem $item, ProjectWialonGroup $group): bool
    {
        if ($this->eligibleEquipmentCount($group) === 0) {
            return false;
        }

        if ((int) $item->rows_saved <= 0) {
            return true;
        }

        $includedRows = EngineHoursReportUnitDay::query()
            ->whereDate('stat_date', $item->report_date?->toDateString())
            ->where('project_id', $group->project_id)
            ->where('ownership_type', $group->ownership_type)
            ->where('engine_hours_source', EngineHoursReportUnitDay::SOURCE)
            ->where('parse_status', 'ok')
            ->whereNotNull('engine_hours')
            ->where('engine_hours', '>=', 0)
            ->whereIn('vehicle_type', FleetVehicleType::names(FleetVehicleType::TOP_WORKING_TYPES))
            ->count();

        return $includedRows === 0;
    }

    private function eligibleEquipmentCount(ProjectWialonGroup $group): int
    {
        return Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->where('equipments.project_id', $group->project_id)
            ->where('equipments.ownership_type', $group->ownership_type)
            ->whereIn('equipment_types.name', FleetVehicleType::names(FleetVehicleType::TOP_WORKING_TYPES))
            ->count();
    }

    private function dueItems(array $filters): Collection
    {
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));
        $statuses = [WialonReportSyncItem::STATUS_PENDING, WialonReportSyncItem::STATUS_RETRY];

        return WialonReportSyncItem::query()
            ->where('sync_type', WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20)
            ->whereIn('status', $statuses)
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now($this->timezone()));
            })
            ->when(! empty($filters['date']), fn (Builder $query) => $query->whereDate('report_date', $filters['date']))
            ->when(! empty($filters['group']), fn (Builder $query) => $query->where('wialon_group_id', trim((string) $filters['group'])))
            ->orderBy('report_date')
            ->orderBy('wialon_group_id')
            ->limit($limit)
            ->get();
    }

    private function ownership(mixed $value): ?string
    {
        $raw = trim((string) $value);
        $value = mb_strtolower($raw);

        return match ($value) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => in_array(strtoupper($raw), [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true) ? strtoupper($raw) : null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function allowedTypes(): array
    {
        return collect(config('fleet_efficiency.top_working_vehicle_types', []))
            ->map(fn (string $type): string => FleetVehicleType::slug($type))
            ->all();
    }

    private function unitKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function isTemporary(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'temporary')
            || str_contains($message, 'timeout')
            || str_contains($message, 'busy')
            || str_contains($message, 'token/login')
            || str_contains($message, 'wialon api error 1003')
            || str_contains($message, 'wialon api error 1004');
    }

    private function errorCode(Throwable $exception): string
    {
        if (preg_match('/Wialon API error\s+([0-9]+)/i', $exception->getMessage(), $matches)) {
            return $matches[1];
        }

        return str_contains(mb_strtolower($exception->getMessage()), 'template') ? 'template' : 'exception';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $filters): array
    {
        $timezone = $this->timezone();
        $date = $filters['date'] ?? null;
        $from = CarbonImmutable::parse((string) ($date ?: ($filters['from'] ?? $filters['date_from'] ?? now($timezone)->toDateString())), $timezone)->startOfDay();
        $to = CarbonImmutable::parse((string) ($date ?: ($filters['to'] ?? $filters['date_to'] ?? $from->toDateString())), $timezone)->endOfDay();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function timezone(): string
    {
        return (string) config('fleet_efficiency.timezone', config('app.timezone', 'Asia/Baku'));
    }

    private function bumpDashboardDataVersion(): void
    {
        Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
    }
}
