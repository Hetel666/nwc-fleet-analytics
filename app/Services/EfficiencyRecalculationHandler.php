<?php

namespace App\Services;

use App\Models\DailyUnitAggregate;
use App\Models\EfficiencyDailyFact;
use App\Models\EfficiencySyncRun;
use App\Models\EfficiencySyncTask;
use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\ProjectWialonGroup;
use App\Models\WialonReportSyncItem;
use App\Models\WialonSyncCheckpoint;
use App\Support\EfficiencyStatus;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class EfficiencyRecalculationHandler
{
    public function __construct(
        private WialonService $wialon,
        private WialonEfficiencyReportService $reports,
        private WialonEfficiencyReportParser $parser,
        private WialonSessionManager $sessions,
    ) {}

    public function execute(HistoricalRecalculation $historicalRun, HistoricalRecalculationTask $historicalTask): int
    {
        $date = CarbonImmutable::parse($historicalTask->stat_date, $historicalRun->timezone);
        $lock = Cache::lock(
            'efficiency:'.$historicalTask->project_id.':'.$date->toDateString(),
            (int) config('fleet.wialon.efficiency_sync_lock_seconds', 1800),
        );

        if (! $lock->get()) {
            throw new RuntimeException('Efficiency synchronization is already running for this project and date.');
        }

        $run = $this->syncRun($historicalRun);
        $task = EfficiencySyncTask::query()
            ->where('run_id', $run->id)
            ->where('project_id', $historicalTask->project_id)
            ->whereDate('business_date', $date->toDateString())
            ->first() ?? new EfficiencySyncTask([
                'run_id' => $run->id,
                'project_id' => $historicalTask->project_id,
                'business_date' => $date->toDateString(),
            ]);
        $task->forceFill([
            'status' => 'running',
            'attempts' => (int) $task->attempts + 1,
            'started_at' => now($historicalRun->timezone),
            'completed_at' => null,
            'error_message' => null,
        ])->save();
        $this->refreshRun($run);

        try {
            $result = $this->synchronizeProjectDate($run, $task, $date, (bool) $historicalRun->force);

            $task->forceFill([
                'wialon_group_id' => implode(',', $result['group_ids']),
                'status' => 'completed',
                'report_rows_received' => $result['report_rows_received'],
                'eligible_units_count' => $result['eligible_units_count'],
                'facts_saved_count' => $result['facts_saved_count'],
                'missing_units_count' => $result['missing_units_count'],
                'unmatched_report_rows' => $result['unmatched_report_rows'],
                'completed_at' => now($historicalRun->timezone),
                'error_message' => null,
            ])->save();
            $this->refreshRun($run);

            if (! app(DashboardReportPipelineService::class)->containsRun((int) $historicalRun->id)) {
                Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
            }

            return $result['facts_saved_count'];
        } catch (Throwable $exception) {
            $task->forceFill([
                'status' => 'failed',
                'completed_at' => now($historicalRun->timezone),
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();
            $this->refreshRun($run, $exception->getMessage());

            throw $exception;
        } finally {
            $this->sessions->close();
            optional($lock)->release();
        }
    }

    /** @return array<string, mixed> */
    private function synchronizeProjectDate(
        EfficiencySyncRun $run,
        EfficiencySyncTask $task,
        CarbonImmutable $date,
        bool $force,
    ): array {
        $groups = ProjectWialonGroup::query()
            ->where('project_id', $task->project_id)
            ->when(
                Schema::hasColumn('project_wialon_groups', 'is_active'),
                fn (Builder $query): Builder => $query->where('is_active', true),
            )
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->orderBy('ownership_type')
            ->get();

        if ($groups->isEmpty()) {
            throw new RuntimeException('The project has no active NWC or İcarə Wialon groups.');
        }

        $sid = $this->sessions->sid();
        $settings = $this->reports->settings();
        $facts = [];
        $unmatched = [];
        $reportRows = 0;
        $eligibleCount = 0;
        $missingCount = 0;
        $sharedRecords = [];
        $sharedSyncItems = [];

        foreach ($groups as $group) {
            $groupData = collect($this->wialon->getUnitGroups([$group->wialon_group_id]))->first();

            if (! is_array($groupData) || ! array_key_exists('u', $groupData)) {
                throw new RuntimeException("Could not load the full unit list for Wialon group {$group->wialon_group_id}.");
            }

            $memberIds = collect($groupData['u'])->map(fn ($id): string => (string) $id)->unique()->values();
            $equipment = Equipment::query()
                ->with(['type:id,name'])
                ->where('active', true)
                ->visibleInDashboard()
                ->where('project_id', $task->project_id)
                ->where('ownership_type', $group->ownership_type)
                ->whereIn('wialon_unit_id', $memberIds->all())
                ->get()
                ->filter(fn (Equipment $item): bool => in_array(
                    FleetVehicleType::normalize($item->type?->name),
                    FleetVehicleType::EFFICIENCY_TYPES,
                    true,
                ))
                ->keyBy(fn (Equipment $item): string => (string) $item->wialon_unit_id);

            $eligibleCount += $equipment->count();
            $report = $this->reports->execute($group, $date->startOfDay(), $date->endOfDay(), $sid);
            $parsed = $this->parser->parse($report);
            $reportRows += $parsed['rows_received'];
            $sharedSyncItems[(string) $group->wialon_group_id] = [
                'group' => $group,
                'rows_received' => $parsed['rows_received'],
                'rows_saved' => 0,
            ];
            $matchedIds = [];

            foreach ($parsed['records'] as $record) {
                $unitId = $record['wialon_unit_id'];
                $item = $unitId === null ? null : $equipment->get((string) $unitId);

                if (! $item instanceof Equipment) {
                    $unmatched[] = [
                        'project_id' => $task->project_id,
                        'business_date' => $date->toDateString(),
                        'wialon_group_id' => (string) $group->wialon_group_id,
                        'wialon_unit_id' => $unitId,
                        'unit_name' => $record['unit_name'],
                        'reason' => $unitId === null ? 'missing_wialon_unit_id' : 'unit_not_eligible_or_not_mapped',
                        'raw_row_json' => json_encode($record['raw_row_json'], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    continue;
                }

                if (isset($facts[(string) $unitId])) {
                    throw new RuntimeException("Wialon unit {$unitId} is present in more than one project ownership group.");
                }

                $matchedIds[(string) $unitId] = true;
                $facts[(string) $unitId] = $this->factRow($run, $group, $item, $date, $record, $settings);
                $sharedRecords[(int) $item->id] = [
                    'group' => $group,
                    'equipment' => $item,
                    'record' => $record,
                    'settings' => $settings,
                ];
            }

            foreach ($equipment as $unitId => $item) {
                if (isset($matchedIds[(string) $unitId])) {
                    continue;
                }

                $missingCount++;
                $facts[(string) $unitId] = $this->factRow($run, $group, $item, $date, [
                    'unit_name' => $item->name,
                    'engine_hours_decimal' => 0,
                    'engine_seconds' => 0,
                    'engine_hours_raw' => null,
                    'started_at' => null,
                    'ended_at' => null,
                    'mileage_km' => null,
                    'mileage_raw' => null,
                    'raw_row_json' => null,
                ], $settings);
            }
        }

        DB::transaction(function () use ($task, $date, $facts, $unmatched, $force, $sharedRecords, &$sharedSyncItems): void {
            $unitIds = array_keys($facts);
            $stale = EfficiencyDailyFact::query()
                ->where('project_id', $task->project_id)
                ->whereDate('business_date', $date->toDateString());

            if ($force) {
                $stale->delete();
            } else {
                $unitIds === [] ? $stale->delete() : $stale->whereNotIn('wialon_unit_id', $unitIds)->delete();
            }

            if ($facts !== []) {
                if ($force) {
                    EfficiencyDailyFact::query()->insert(array_values($facts));
                } else {
                    EfficiencyDailyFact::query()->upsert(
                        array_values($facts),
                        ['business_date', 'project_id', 'wialon_unit_id'],
                        [
                            'wialon_group_id', 'unit_name', 'vehicle_type', 'ownership',
                            'engine_hours_decimal', 'engine_seconds', 'engine_hours_raw',
                            'started_at', 'ended_at', 'mileage_km', 'mileage_raw',
                            'efficiency_status', 'report_run_id', 'source_report_template_id',
                            'source_report_name', 'raw_row_json', 'updated_at',
                        ],
                    );
                }
            }

            DB::table('efficiency_unmatched_report_rows')->where('task_id', $task->id)->delete();

            if ($unmatched !== []) {
                DB::table('efficiency_unmatched_report_rows')->insert(
                    array_map(fn (array $row): array => ['task_id' => $task->id, ...$row], $unmatched),
                );
            }

            $this->replaceSharedEngineHoursFacts($task, $date, $sharedRecords, $sharedSyncItems);
        });

        return [
            'group_ids' => $groups->pluck('wialon_group_id')->map(fn ($id): string => (string) $id)->all(),
            'report_rows_received' => $reportRows,
            'eligible_units_count' => $eligibleCount,
            'facts_saved_count' => count($facts),
            'missing_units_count' => $missingCount,
            'unmatched_report_rows' => count($unmatched),
            'shared_rows_saved' => count($sharedRecords),
        ];
    }

    /** @return array<string, mixed> */
    private function factRow(
        EfficiencySyncRun $run,
        ProjectWialonGroup $group,
        Equipment $equipment,
        CarbonImmutable $date,
        array $record,
        array $settings,
    ): array {
        $seconds = max(0, (int) $record['engine_seconds']);
        $hours = max(0, (float) $record['engine_hours_decimal']);

        return [
            'business_date' => $date->toDateString(),
            'project_id' => $group->project_id,
            'wialon_group_id' => (string) $group->wialon_group_id,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => $record['unit_name'] ?: $equipment->name,
            'vehicle_type' => FleetVehicleType::label($equipment->type?->name),
            'ownership' => $group->ownership_type,
            'engine_hours_decimal' => number_format($hours, 2, '.', ''),
            'engine_seconds' => $seconds,
            'engine_hours_raw' => $record['engine_hours_raw'],
            'started_at' => $record['started_at'],
            'ended_at' => $record['ended_at'],
            'mileage_km' => $record['mileage_km'],
            'mileage_raw' => $record['mileage_raw'],
            'efficiency_status' => EfficiencyStatus::classify($seconds),
            'report_run_id' => $run->id,
            'source_report_template_id' => $settings['template_id'],
            'source_report_name' => $settings['template_name'],
            'raw_row_json' => $record['raw_row_json'] === null ? null : json_encode($record['raw_row_json'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<int, array{group: ProjectWialonGroup, equipment: Equipment, record: array<string, mixed>, settings: array<string, mixed>}>  $sharedRecords
     * @param  array<string, array<string, mixed>>  $syncItems
     */
    private function replaceSharedEngineHoursFacts(
        EfficiencySyncTask $task,
        CarbonImmutable $date,
        array $sharedRecords,
        array &$syncItems,
    ): void {
        $projectId = (int) $task->project_id;
        $timezone = (string) config('historical_recalculation.timezone', config('app.timezone', 'Asia/Baku'));

        EquipmentDailyStat::query()
            ->where('stat_date', $date->toDateString())
            ->where('project_id', $projectId)
            ->where('calculation_source', 'wialon_engine_hours_report')
            ->delete();

        DailyUnitAggregate::query()
            ->where('date', $date->toDateString())
            ->where('project_id', $projectId)
            ->delete();

        EngineHoursReportUnitDay::query()
            ->where('stat_date', $date->toDateString())
            ->where('project_id', $projectId)
            ->delete();

        foreach ($sharedRecords as $payload) {
            $group = $payload['group'];
            $equipment = $payload['equipment'];
            $record = $payload['record'];
            $settings = $payload['settings'];
            $workedHours = round(max(0, (float) ($record['engine_hours_decimal'] ?? 0)), 2);
            $distanceKm = round(max(0, (float) ($record['mileage_km'] ?? 0)), 2);
            $utilization = $equipment->planned_daily_hours > 0
                ? min(100, ($workedHours / (float) $equipment->planned_daily_hours) * 100)
                : 0;
            $dailyStat = EquipmentDailyStat::query()->create([
                'stat_date' => $date->toDateString(),
                'equipment_id' => $equipment->id,
                'project_id' => $equipment->project_id,
                'ownership_type' => $equipment->ownership_type,
                'worked_hours' => $workedHours,
                'distance_km' => $distanceKm,
                'utilization_percent' => round($utilization, 2),
                'calculation_source' => 'wialon_engine_hours_report',
                'calculation_status' => 'success',
                'report_resource_id' => (string) ($settings['resource_id'] ?? ''),
                'report_template_id' => (string) ($settings['template_id'] ?? ''),
                'source_group_id' => (string) $group->wialon_group_id,
                'calculated_at' => now($timezone),
            ]);

            if (trim((string) $equipment->wialon_unit_id) !== '') {
                DailyUnitAggregate::query()->create([
                    'date' => $date->toDateString(),
                    'unit_id' => (string) $equipment->wialon_unit_id,
                    'equipment_id' => $equipment->id,
                    'project_id' => $equipment->project_id,
                    'equipment_type_id' => $equipment->equipment_type_id,
                    'ownership_type' => $equipment->ownership_type,
                    'engine_hours' => $workedHours,
                    'mileage' => $distanceKm,
                    'geofence_outside_hours' => round(((float) $dailyStat->outside_geofence_minutes) / 60, 2),
                ]);
            }

            if ($this->isTopWorkingType($equipment)) {
                EngineHoursReportUnitDay::query()->create([
                    'stat_date' => $date->toDateString(),
                    'equipment_id' => $equipment->id,
                    'project_id' => $equipment->project_id,
                    'equipment_type_id' => $equipment->equipment_type_id,
                    'ownership_type' => $equipment->ownership_type,
                    'wialon_unit_id' => (string) $equipment->wialon_unit_id,
                    'unit_name' => (string) ($record['unit_name'] ?: $equipment->name),
                    'vehicle_type' => FleetVehicleType::display($equipment->type?->name),
                    'engine_hours' => $workedHours,
                    'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
                    'parse_status' => 'ok',
                    'report_resource_id' => (string) ($settings['resource_id'] ?? ''),
                    'report_template_id' => (string) ($settings['template_id'] ?? ''),
                    'report_template_name' => (string) ($settings['template_name'] ?? ''),
                    'source_table' => (string) ($record['source_table'] ?? 'Qrup report Engine hours'),
                    'engine_hours_column_index' => $record['engine_hours_column_index'] ?? null,
                    'engine_hours_column_label' => (string) ($record['engine_hours_column_label'] ?? 'Engine hours'),
                    'source_group_ids_json' => [(string) $group->wialon_group_id],
                    'raw_value_json' => [
                        't' => $record['engine_hours_raw'] ?? null,
                        'v' => $workedHours,
                    ],
                    'synced_at' => now($timezone),
                ]);

                $syncItems[(string) $group->wialon_group_id]['rows_saved'] =
                    (int) ($syncItems[(string) $group->wialon_group_id]['rows_saved'] ?? 0) + 1;
            }
        }

        foreach ($syncItems as $syncItem) {
            $group = $syncItem['group'];
            $item = WialonReportSyncItem::query()->firstOrNew([
                'sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
                'report_date' => $date->toDateString(),
                'wialon_group_id' => (string) $group->wialon_group_id,
            ]);
            $item->forceFill([
                'wialon_group_name' => $group->name,
                'status' => WialonReportSyncItem::STATUS_COMPLETED,
                'attempts' => (int) $item->attempts + 1,
                'rows_received' => (int) ($syncItem['rows_received'] ?? 0),
                'rows_saved' => (int) ($syncItem['rows_saved'] ?? 0),
                'started_at' => $task->started_at ?: now($timezone),
                'finished_at' => now($timezone),
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'run_id' => 'efficiency-sync:'.$task->run_id.':'.$task->id,
            ])->save();

            WialonSyncCheckpoint::query()->updateOrCreate(
                [
                    'checkpoint_key' => $this->sharedEngineHoursCheckpointKey($date, $group),
                ],
                [
                    'sync_type' => WialonSyncCheckpoint::TYPE_DAILY_ENGINE_STATS,
                    'report_date' => $date->toDateString(),
                    'project_id' => $projectId,
                    'ownership_type' => $group->ownership_type,
                    'wialon_group_id' => (string) $group->wialon_group_id,
                    'status' => 'success',
                    'equipment_count' => (int) ($syncItem['rows_saved'] ?? 0),
                    'payload' => [
                        'status' => 'success',
                        'source' => 'Qrup report Engine hours (api)',
                        'rows_received' => (int) ($syncItem['rows_received'] ?? 0),
                        'rows_saved' => (int) ($syncItem['rows_saved'] ?? 0),
                        'synced_at' => now($timezone)->toIso8601String(),
                    ],
                    'completed_at' => now($timezone),
                ],
            );
        }
    }

    private function isTopWorkingType(Equipment $equipment): bool
    {
        $allowed = collect(config('fleet_efficiency.top_working_vehicle_types', []))
            ->map(fn (string $type): string => FleetVehicleType::slug($type))
            ->all();

        return in_array(FleetVehicleType::slug(FleetVehicleType::display($equipment->type?->name)), $allowed, true);
    }

    private function sharedEngineHoursCheckpointKey(CarbonImmutable $date, ProjectWialonGroup $group): string
    {
        return 'wialon_daily_engine_sync:'.sha1(json_encode([
            'version' => 2,
            'source' => 'qrup_engine_hours',
            'project_id' => $group->project_id,
            'ownership_type' => $group->ownership_type,
            'group_id' => $group->wialon_group_id,
            'date' => $date->toDateString(),
        ]));
    }

    private function syncRun(HistoricalRecalculation $historicalRun): EfficiencySyncRun
    {
        $run = EfficiencySyncRun::query()->updateOrCreate(
            ['historical_recalculation_id' => $historicalRun->id],
            [
                'date_from' => $historicalRun->date_from,
                'date_to' => $historicalRun->date_to,
                'status' => 'running',
                'started_at' => $historicalRun->started_at ?: now($historicalRun->timezone),
                'completed_at' => null,
                'created_by' => $historicalRun->requested_by,
                'error_message' => null,
            ],
        );

        foreach ($historicalRun->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->get() as $task) {
            $exists = EfficiencySyncTask::query()
                ->where('run_id', $run->id)
                ->where('project_id', $task->project_id)
                ->whereDate('business_date', $task->stat_date->toDateString())
                ->exists();

            if (! $exists) {
                EfficiencySyncTask::query()->create([
                    'run_id' => $run->id,
                    'project_id' => $task->project_id,
                    'business_date' => $task->stat_date->toDateString(),
                    'status' => 'pending',
                ]);
            }
        }

        return $run;
    }

    private function refreshRun(EfficiencySyncRun $run, ?string $error = null): void
    {
        $counts = $run->tasks()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $run->tasks()->count();
        $pending = (int) ($counts['pending'] ?? 0);
        $running = (int) ($counts['running'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);
        $terminal = $total > 0 && $pending === 0 && $running === 0;

        $run->forceFill([
            'status' => $terminal ? ($failed > 0 ? 'failed' : 'completed') : 'running',
            'total_tasks' => $total,
            'pending_tasks' => $pending,
            'running_tasks' => $running,
            'completed_tasks' => $completed,
            'failed_tasks' => $failed,
            'completed_at' => $terminal ? now() : null,
            'error_message' => $error === null ? $run->error_message : mb_substr($error, 0, 4000),
        ])->save();
    }
}
