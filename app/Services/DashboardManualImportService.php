<?php

namespace App\Services;

use App\Models\DashboardDataImport;
use App\Models\DashboardDataImportRow;
use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Support\EfficiencyStatus;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DashboardManualImportService
{
    public function __construct(private WialonManualXlsxParser $parser) {}

    /**
     * @param  array<int, array{path:string,name:string}>  $files
     */
    public function stage(DashboardDataImport $import, array $files): DashboardDataImport
    {
        $import->rows()->delete();
        $from = CarbonImmutable::parse($import->date_from)->startOfDay();
        $to = CarbonImmutable::parse($import->date_to)->startOfDay();
        $equipmentIndex = $this->equipmentIndex($import);
        $staged = [];
        $accepted = [];
        $sourceRows = 0;
        $duplicates = 0;
        $tables = [];

        try {
            foreach ($files as $fileIndex => $file) {
                $parsed = $this->parser->parse($file['path'], $file['name'], $import->module, $from, $to);
                $sourceRows += $parsed['source_rows'];
                $tables = array_values(array_unique([...$tables, ...$parsed['tables']]));

                foreach ($parsed['errors'] as $error) {
                    $staged[] = $this->stagedRow($import, $fileIndex, $error, DashboardDataImportRow::STATUS_REJECTED);
                }

                foreach ($parsed['records'] as $record) {
                    $resolution = $this->resolveEquipment($record, $equipmentIndex);

                    if ($resolution['equipment'] === null) {
                        $record['message'] = $resolution['message'];
                        $staged[] = $this->stagedRow($import, $fileIndex, $record, DashboardDataImportRow::STATUS_REJECTED);

                        continue;
                    }

                    $payload = $this->canonicalPayload($record, $resolution['equipment'], $resolution['group']);
                    $key = $this->rowKey($payload);
                    $signature = $this->metricSignature($payload);

                    if (isset($accepted[$key])) {
                        if ($accepted[$key]['signature'] === $signature) {
                            $duplicates++;
                            $record['message'] = 'Eyni məlumat sətri təkrarlandığı üçün nəzərə alınmadı.';
                            $staged[] = $this->stagedRow($import, $fileIndex, $record, DashboardDataImportRow::STATUS_DUPLICATE, $key);

                            continue;
                        }

                        if (in_array($payload['kind'], ['daily', 'monthly_geofence'], true)) {
                            $accepted[$key]['payload'] = $this->mergePayload($accepted[$key]['payload'], $payload);
                            $accepted[$key]['signature'] = $this->metricSignature($accepted[$key]['payload']);

                            continue;
                        }

                        $record['message'] = 'Eyni texnika və tarix üçün ziddiyyətli yekun motosaat tapıldı.';
                        $staged[] = $this->stagedRow($import, $fileIndex, $record, DashboardDataImportRow::STATUS_REJECTED, $key);

                        continue;
                    }

                    $accepted[$key] = [
                        'file_index' => $fileIndex,
                        'record' => $record,
                        'payload' => $payload,
                        'signature' => $signature,
                    ];
                }
            }

            foreach ($accepted as $key => $item) {
                $item['record']['payload_json'] = $item['payload'];
                $staged[] = $this->stagedRow(
                    $import,
                    $item['file_index'],
                    $item['record'],
                    DashboardDataImportRow::STATUS_ACCEPTED,
                    $key,
                );
            }

            foreach (array_chunk($staged, 500) as $rows) {
                DB::table('dashboard_data_import_rows')->insert($rows);
            }

            $acceptedCount = count($accepted);
            $rejectedCount = collect($staged)->where('status', DashboardDataImportRow::STATUS_REJECTED)->count();
            $status = $acceptedCount > 0 && $rejectedCount === 0
                ? DashboardDataImport::STATUS_READY
                : DashboardDataImport::STATUS_INVALID;

            $import->forceFill([
                'status' => $status,
                'source_rows' => $sourceRows,
                'accepted_rows' => $acceptedCount,
                'rejected_rows' => $rejectedCount,
                'duplicate_rows' => $duplicates,
                'summary_json' => [
                    'tables' => $tables,
                    'units' => collect($accepted)->pluck('payload.wialon_unit_id')->unique()->count(),
                    'projects' => collect($accepted)->pluck('payload.project_name')->filter()->unique()->values()->all(),
                    'dates' => collect($accepted)->pluck('payload.business_date')->unique()->sort()->values()->all(),
                ],
                'error_message' => $rejectedCount > 0 ? 'Yoxlama xətaları aradan qaldırılmadan import təsdiqlənə bilməz.' : null,
            ])->save();
        } catch (Throwable $exception) {
            $import->forceFill([
                'status' => DashboardDataImport::STATUS_INVALID,
                'error_message' => $exception->getMessage(),
            ])->save();
        }

        return $import->fresh(['project', 'creator']);
    }

    public function commit(DashboardDataImport $import): DashboardDataImport
    {
        if (! $import->canBeConfirmed()) {
            throw new RuntimeException('Import təsdiq üçün hazır deyil.');
        }

        $claimed = DashboardDataImport::query()
            ->whereKey($import->getKey())
            ->where('status', DashboardDataImport::STATUS_READY)
            ->where('accepted_rows', '>', 0)
            ->where('rejected_rows', 0)
            ->update([
                'status' => DashboardDataImport::STATUS_IMPORTING,
                'confirmed_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new RuntimeException('Import artıq icraya götürülüb və ya təsdiq üçün hazır deyil.');
        }

        $import->refresh();

        try {
            [$deleted, $written] = DB::transaction(function () use ($import): array {
                return $import->module === DashboardDataImport::MODULE_MONTHLY_EFFICIENCY
                    ? $this->commitMonthly($import)
                    : $this->commitDaily($import);
            }, 3);

            Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
            Cache::forever('monthly_efficiency:data-version', ((int) Cache::get('monthly_efficiency:data-version', 1)) + 1);

            $import->forceFill([
                'status' => DashboardDataImport::STATUS_COMPLETED,
                'deleted_rows' => $deleted,
                'written_rows' => $written,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $import->forceFill([
                'status' => DashboardDataImport::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }

        return $import->fresh(['project', 'creator']);
    }

    /** @return array{0:int,1:int} */
    private function commitDaily(DashboardDataImport $import): array
    {
        $payloads = $this->acceptedPayloads($import);
        $equipmentIds = $payloads->pluck('equipment_id')->unique()->map(fn ($id): int => (int) $id)->all();
        $unitIds = $payloads->pluck('wialon_unit_id')->filter()->unique()->map(fn ($id): string => (string) $id)->all();

        $deleted = DB::table('efficiency_daily_facts')
            ->whereDate('business_date', '>=', $import->date_from->toDateString())
            ->whereDate('business_date', '<=', $import->date_to->toDateString())
            ->whereIn('wialon_unit_id', $unitIds)
            ->delete();

        $deleted += DB::table('equipment_daily_stats')
            ->whereDate('stat_date', '>=', $import->date_from->toDateString())
            ->whereDate('stat_date', '<=', $import->date_to->toDateString())
            ->whereIn('equipment_id', $equipmentIds)
            ->delete();

        $deleted += DB::table('daily_unit_aggregates')
            ->whereDate('date', '>=', $import->date_from->toDateString())
            ->whereDate('date', '<=', $import->date_to->toDateString())
            ->whereIn('equipment_id', $equipmentIds)
            ->delete();

        $now = now();
        $sourceName = (string) config('fleet.wialon.efficiency_report_template_name', 'Qrup date report Engine hours (api)');
        $facts = [];
        $dailyStats = [];
        $aggregates = [];

        foreach ($payloads as $payload) {
            $facts[] = [
                'business_date' => $payload['business_date'],
                'project_id' => $payload['project_id'],
                'wialon_group_id' => $payload['wialon_group_id'],
                'wialon_unit_id' => $payload['wialon_unit_id'],
                'unit_name' => $payload['unit_name'],
                'vehicle_type' => $payload['vehicle_type'],
                'ownership' => $payload['ownership_type'],
                'engine_hours_decimal' => $payload['engine_hours_decimal'],
                'engine_seconds' => $payload['engine_seconds'],
                'engine_hours_raw' => $payload['engine_hours_raw'],
                'started_at' => $payload['started_at'],
                'ended_at' => $payload['ended_at'],
                'mileage_km' => $payload['mileage_km'],
                'mileage_raw' => $payload['mileage_raw'],
                'efficiency_status' => EfficiencyStatus::classify((int) $payload['engine_seconds']),
                'report_run_id' => null,
                'source_report_template_id' => 0,
                'source_report_name' => $sourceName,
                'raw_row_json' => json_encode($payload['raw_rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $planned = max(0.0, (float) $payload['planned_daily_hours']);
            $hours = (float) $payload['engine_hours_decimal'];
            $utilization = $planned > 0 ? min(100, ($hours / $planned) * 100) : 0;
            $dailyStats[] = [
                'stat_date' => $payload['business_date'],
                'equipment_id' => $payload['equipment_id'],
                'project_id' => $payload['project_id'],
                'ownership_type' => $payload['ownership_type'],
                'worked_hours' => $hours,
                'daytime_hours' => null,
                'daytime_seconds' => null,
                'overtime_hours' => null,
                'overtime_seconds' => null,
                'total_hours' => $hours,
                'total_seconds' => $payload['engine_seconds'],
                'day_status' => EfficiencyStatus::classify((int) $payload['engine_seconds']),
                'has_overtime' => null,
                'data_available' => true,
                'daytime_data_available' => null,
                'overtime_data_available' => null,
                'distance_km' => max(0, (float) ($payload['mileage_km'] ?? 0)),
                'utilization_percent' => round($utilization, 2),
                'geofence_exit_count' => 0,
                'outside_geofence_minutes' => 0,
                'first_message_at' => $payload['started_at'],
                'last_message_at' => $payload['ended_at'],
                'calculation_source' => 'manual_wialon_xlsx',
                'calculation_status' => 'success',
                'report_resource_id' => null,
                'report_template_id' => 'manual',
                'source_group_id' => $payload['wialon_group_id'],
                'source_intervals_json' => json_encode(['import_uuid' => $import->uuid], JSON_UNESCAPED_UNICODE),
                'calculated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $aggregates[] = [
                'date' => $payload['business_date'],
                'unit_id' => $payload['wialon_unit_id'],
                'equipment_id' => $payload['equipment_id'],
                'project_id' => $payload['project_id'],
                'equipment_type_id' => $payload['equipment_type_id'],
                'ownership_type' => $payload['ownership_type'],
                'engine_hours' => $hours,
                'mileage' => max(0, (float) ($payload['mileage_km'] ?? 0)),
                'geofence_outside_hours' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($facts, 500) as $rows) {
            DB::table('efficiency_daily_facts')->insert($rows);
        }
        foreach (array_chunk($dailyStats, 500) as $rows) {
            DB::table('equipment_daily_stats')->insert($rows);
        }
        foreach (array_chunk($aggregates, 500) as $rows) {
            DB::table('daily_unit_aggregates')->insert($rows);
        }

        return [$deleted, count($facts) + count($dailyStats) + count($aggregates)];
    }

    /** @return array{0:int,1:int} */
    private function commitMonthly(DashboardDataImport $import): array
    {
        $payloads = $this->acceptedPayloads($import);
        $unitIds = $payloads->pluck('wialon_unit_id')->filter()->unique()->map(fn ($id): string => (string) $id)->all();
        $deleted = DB::table('monthly_efficiency_unit_geofence_facts')
            ->whereDate('stat_date', '>=', $import->date_from->toDateString())
            ->whereDate('stat_date', '<=', $import->date_to->toDateString())
            ->whereIn('wialon_unit_id', $unitIds)
            ->delete();

        $grouped = collect($payloads)->groupBy(fn (array $row): string => $row['business_date'].'|'.$row['wialon_unit_id']);
        $facts = [];
        $sourceName = (string) config('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Report for Aylıq effektivlik');
        $unknownLabel = (string) config('fleet.wialon.monthly_efficiency_unknown_label', 'Naməlum');
        $now = now();

        foreach ($grouped as $rows) {
            $total = $rows->firstWhere('kind', 'monthly_total');

            if ($total === null) {
                throw new RuntimeException('Aylıq importda texnika-gün üçün Engine hours yekunu çatışmır.');
            }

            $geofences = $rows->where('kind', 'monthly_geofence')->values();
            $knownHours = round((float) $geofences->sum('engine_hours_decimal'), 2);
            $totalHours = round((float) $total['engine_hours_decimal'], 2);
            $ratio = $knownHours > $totalHours && $knownHours > 0 ? max(0, $totalHours) / $knownHours : 1.0;
            $knownHours = 0.0;
            $knownMileage = 0.0;

            $facts[] = $this->monthlyFact($total, 'total', 'Total', $totalHours, (int) $total['engine_seconds'], (float) ($total['mileage_km'] ?? 0), (int) ($total['visits_count'] ?? 0), $sourceName, $now);

            foreach ($geofences as $geofence) {
                $hours = round((float) $geofence['engine_hours_decimal'] * $ratio, 2);
                $mileage = round((float) ($geofence['mileage_km'] ?? 0) * $ratio, 2);
                $knownHours += $hours;
                $knownMileage += $mileage;
                $facts[] = $this->monthlyFact(
                    $geofence,
                    'geofence',
                    (string) $geofence['geofence_name'],
                    $hours,
                    (int) round($hours * 3600),
                    $mileage,
                    (int) ($geofence['visits_count'] ?? 0),
                    $sourceName,
                    $now,
                );
            }

            $unknownHours = max(0, round($totalHours - $knownHours, 2));
            $unknownMileage = max(0, round((float) ($total['mileage_km'] ?? 0) - $knownMileage, 2));
            $unknown = [...$total, 'started_at' => null, 'ended_at' => null, 'raw_rows' => [['calculated' => true]]];
            $facts[] = $this->monthlyFact($unknown, 'unknown', $unknownLabel, $unknownHours, (int) round($unknownHours * 3600), $unknownMileage, 0, $sourceName, $now);
        }

        foreach (array_chunk($facts, 500) as $rows) {
            DB::table('monthly_efficiency_unit_geofence_facts')->insert($rows);
        }

        return [$deleted, count($facts)];
    }

    /** @return array<string,mixed> */
    private function monthlyFact(array $payload, string $segment, string $geofence, float $hours, int $seconds, float $mileage, int $visits, string $sourceName, mixed $now): array
    {
        return [
            'stat_date' => $payload['business_date'],
            'equipment_id' => $payload['equipment_id'],
            'project_id' => $payload['project_id'],
            'project_wialon_group_id' => $payload['project_wialon_group_id'],
            'wialon_group_id' => $payload['wialon_group_id'],
            'wialon_unit_id' => $payload['wialon_unit_id'],
            'unit_name' => $payload['unit_name'],
            'registration_number' => $payload['registration_number'],
            'vehicle_type' => $payload['vehicle_type'],
            'ownership_type' => $payload['ownership_type'],
            'segment_type' => $segment,
            'geofence_name' => $geofence,
            'engine_hours_decimal' => $hours,
            'engine_seconds' => $seconds,
            'mileage_km' => max(0, $mileage),
            'visits_count' => max(0, $visits),
            'started_at' => $payload['started_at'],
            'ended_at' => $payload['ended_at'],
            'source_report_template_id' => 0,
            'source_report_name' => $sourceName,
            'raw_row_json' => json_encode($payload['raw_rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function acceptedPayloads(DashboardDataImport $import): Collection
    {
        return $import->rows()
            ->where('status', DashboardDataImportRow::STATUS_ACCEPTED)
            ->orderBy('id')
            ->get()
            ->map(fn (DashboardDataImportRow $row): array => (array) $row->payload_json);
    }

    /** @return array<string,mixed> */
    private function canonicalPayload(array $record, Equipment $equipment, ProjectWialonGroup $group): array
    {
        return [
            'kind' => $record['kind'],
            'business_date' => $record['business_date'],
            'equipment_id' => (int) $equipment->id,
            'equipment_type_id' => (int) $equipment->equipment_type_id,
            'project_id' => (int) $group->project_id,
            'project_name' => (string) $group->project->name,
            'project_wialon_group_id' => (int) $group->id,
            'wialon_group_id' => (string) $group->wialon_group_id,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => (string) $equipment->name,
            'registration_number' => $equipment->registration_number ?: null,
            'vehicle_type' => FleetVehicleType::display($equipment->type?->name),
            'ownership_type' => (string) ($group->ownership_type ?: $equipment->ownership_type),
            'planned_daily_hours' => (float) $equipment->planned_daily_hours,
            'geofence_name' => $record['geofence_name'],
            'engine_hours_decimal' => (float) $record['engine_hours_decimal'],
            'engine_seconds' => (int) $record['engine_seconds'],
            'engine_hours_raw' => $record['engine_hours_raw'],
            'mileage_km' => $record['mileage_km'],
            'mileage_raw' => $record['mileage_raw'],
            'visits_count' => (int) $record['visits_count'],
            'started_at' => $record['started_at'],
            'ended_at' => $record['ended_at'],
            'raw_rows' => [$record['raw_row']],
        ];
    }

    /** @return array<string,mixed> */
    private function mergePayload(array $existing, array $incoming): array
    {
        $seconds = (int) $existing['engine_seconds'] + (int) $incoming['engine_seconds'];
        $existing['engine_seconds'] = $seconds;
        $existing['engine_hours_decimal'] = round($seconds / 3600, 2);
        $existing['mileage_km'] = $this->sumNullable($existing['mileage_km'], $incoming['mileage_km']);
        $existing['visits_count'] = (int) $existing['visits_count'] + (int) $incoming['visits_count'];
        $existing['started_at'] = $this->earliest($existing['started_at'], $incoming['started_at']);
        $existing['ended_at'] = $this->latest($existing['ended_at'], $incoming['ended_at']);
        $existing['raw_rows'] = [...$existing['raw_rows'], ...$incoming['raw_rows']];

        return $existing;
    }

    private function rowKey(array $payload): string
    {
        return implode('|', array_filter([
            $payload['kind'],
            $payload['business_date'],
            $payload['project_id'],
            $payload['wialon_unit_id'],
            $payload['geofence_name'],
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    private function metricSignature(array $payload): string
    {
        return hash('sha256', json_encode([
            $payload['engine_seconds'],
            $payload['mileage_km'],
            $payload['visits_count'],
            $payload['started_at'],
            $payload['ended_at'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{equipment:?Equipment,group:?ProjectWialonGroup,message:?string} */
    private function resolveEquipment(array $record, array $index): array
    {
        $unitId = trim((string) ($record['wialon_unit_id'] ?? ''));
        $name = $this->unitKey((string) ($record['unit_name'] ?? ''));
        $matches = $unitId !== '' ? ($index['ids'][$unitId] ?? []) : [];

        if ($matches === [] && $name !== '') {
            $matches = $index['names'][$name] ?? [];
        }

        if (count($matches) !== 1) {
            $message = $matches === []
                ? 'Texnika kataloqda və project_wialon_groups bağlılığında tapılmadı.'
                : 'Texnika adı bir neçə kataloq obyektinə uyğundur; Wialon Unit ID tələb olunur.';

            return ['equipment' => null, 'group' => null, 'message' => $message];
        }

        $equipment = $matches[0]['equipment'];
        $group = $matches[0]['group'];

        return ['equipment' => $equipment, 'group' => $group, 'message' => null];
    }

    /** @return array{ids:array<string,array<int,array<string,mixed>>>,names:array<string,array<int,array<string,mixed>>>} */
    private function equipmentIndex(DashboardDataImport $import): array
    {
        $index = ['ids' => [], 'names' => []];

        foreach ($this->scopeEquipment($import) as $equipment) {
            $group = $this->groupForEquipment($equipment);

            if ($group === null) {
                continue;
            }

            $entry = ['equipment' => $equipment, 'group' => $group];
            $unitId = trim((string) $equipment->wialon_unit_id);

            if ($unitId !== '') {
                $index['ids'][$unitId][] = $entry;
            }

            foreach ([$equipment->name, $equipment->registration_number] as $name) {
                $key = $this->unitKey((string) $name);

                if ($key !== '') {
                    $index['names'][$key][] = $entry;
                }
            }
        }

        return $index;
    }

    private function scopeEquipment(DashboardDataImport $import): EloquentCollection
    {
        return Equipment::query()
            ->with(['type', 'project', 'projectWialonGroup.project'])
            ->where('active', true)
            ->visibleInDashboard()
            ->boundToProjectWialonGroup()
            ->get()
            ->filter(function (Equipment $equipment) use ($import): bool {
                $group = $this->groupForEquipment($equipment);
                $ownership = (string) ($group?->ownership_type ?: $equipment->ownership_type);

                return $group !== null
                    && (! $import->project_id || (int) $group->project_id === (int) $import->project_id)
                    && (! $import->ownership_type || $ownership === $import->ownership_type)
                    && in_array(
                        FleetVehicleType::normalize($equipment->type?->name),
                        FleetVehicleType::EFFICIENCY_TYPES,
                        true,
                    );
            })
            ->values();
    }

    private function groupForEquipment(Equipment $equipment): ?ProjectWialonGroup
    {
        $group = $equipment->projectWialonGroup;

        if ($group === null && filled($equipment->matched_wialon_group_id)) {
            $group = ProjectWialonGroup::query()
                ->with('project')
                ->where('wialon_group_id', (string) $equipment->matched_wialon_group_id)
                ->where('project_id', $equipment->project_id)
                ->first();
        }

        if ($group === null
            || ($group->is_active !== null && ! $group->is_active)
            || $group->project === null
            || ! $group->project->active
            || Project::isExcludedFromOperationalDashboard($group->project->name)) {
            return null;
        }

        return $group;
    }

    /** @return array<string,mixed> */
    private function stagedRow(DashboardDataImport $import, int $fileIndex, array $record, string $status, ?string $rowKey = null): array
    {
        return [
            'dashboard_data_import_id' => $import->id,
            'file_index' => $fileIndex,
            'file_name' => (string) ($record['file_name'] ?? ($import->original_file_names[$fileIndex] ?? 'report.xlsx')),
            'sheet_name' => $record['sheet_name'] ?? null,
            'source_row_number' => $record['source_row_number'] ?? null,
            'row_key' => $rowKey,
            'status' => $status,
            'message' => $record['message'] ?? null,
            'payload_json' => isset($record['payload_json'])
                ? json_encode($record['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function unitKey(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[^\pL\pN]+/u', '', $value) ?? '';
    }

    private function sumNullable(mixed $left, mixed $right): ?float
    {
        if ($left === null && $right === null) {
            return null;
        }

        return round(max(0, (float) $left) + max(0, (float) $right), 2);
    }

    private function earliest(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return strcmp($left, $right) <= 0 ? $left : $right;
    }

    private function latest(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return strcmp($left, $right) >= 0 ? $left : $right;
    }
}
