<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\WialonReportTemplate;
use App\Models\WialonUnitGroup;
use App\Services\WialonService;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SyncMonthlyEfficiencyObjects extends Command
{
    private const SEGMENT_TOTAL = 'total';

    private const SEGMENT_GEOFENCE = 'geofence';

    private const SEGMENT_UNKNOWN = 'unknown';

    protected $signature = 'monthly-efficiency:sync-objects
        {--from= : Start date}
        {--to= : End date}
        {--project= : Optional local project id}
        {--unit= : Optional Wialon unit id, registration number or unit name}
        {--force : Replace existing object-geofence facts for the selected period}
        {--unit-chunk=25 : Number of units loaded from database at once}
        {--flush-rows=250 : Number of fact rows written per database batch}
        {--historical-task-id= : Optional historical recalculation task id for live progress heartbeats}';

    protected $description = 'Sync Aylıq effektivlik object/geofence facts from the individual Wialon report.';

    public function handle(WialonService $wialon): int
    {
        if (! Schema::hasTable('monthly_efficiency_unit_geofence_facts')) {
            $this->error('monthly_efficiency_unit_geofence_facts table is missing. Run migrations first.');

            return self::FAILURE;
        }

        $timezone = config('app.timezone', 'Asia/Baku');
        $from = CarbonImmutable::parse((string) ($this->option('from') ?: now($timezone)->startOfMonth()->toDateString()), $timezone)->startOfDay();
        $to = CarbonImmutable::parse((string) ($this->option('to') ?: $from->endOfMonth()->toDateString()), $timezone)->endOfDay();

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be before or equal to --to.');

            return self::FAILURE;
        }

        $projectId = $this->projectOption();

        if ($projectId === false) {
            $this->error('The --project option must be a positive integer.');

            return self::FAILURE;
        }

        $template = $this->resolveUnitReportTemplate($wialon);
        $equipmentQuery = $this->equipmentQuery()
            ->when($projectId !== null, fn (Builder $query): Builder => $this->applyProjectFilter($query, $projectId))
            ->when($this->option('unit'), function (Builder $query, string $unit): void {
                $query->where(function (Builder $query) use ($unit): void {
                    $query->where('equipments.wialon_unit_id', $unit)
                        ->orWhere('equipments.registration_number', $unit)
                        ->orWhere('equipments.name', $unit);
                });
            });
        $totalUnits = (clone $equipmentQuery)->count('equipments.id');

        if ($totalUnits === 0) {
            $this->warn('No matching Bulldozer, Excavator or Dump Truck units were found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Syncing %d units with %s / %s for %s - %s.',
            $totalUnits,
            (string) $template['name'],
            (string) $template['template_id'],
            $from->toDateString(),
            $to->toDateString(),
        ));

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $deleted = 0;
        $written = 0;
        $unitChunk = max(1, min(100, (int) $this->option('unit-chunk')));
        $flushRows = max(1, min(1000, (int) $this->option('flush-rows')));
        $progressTaskId = max(0, (int) $this->option('historical-task-id'));
        $processedUnits = 0;

        $equipmentQuery
            ->orderBy('equipments.id')
            ->chunkById($unitChunk, function ($equipment) use ($wialon, $template, $from, $to, $flushRows, $progressTaskId, &$processedUnits, &$ok, &$failed, &$skipped, &$deleted, &$written): void {
                foreach ($equipment as $item) {
                    try {
                        $result = $this->syncOneUnit($wialon, $template, $item, $from, $to, $flushRows);
                        $ok++;
                        $deleted += $result['deleted_rows'];
                        $written += $result['written_rows'];
                        $this->line(sprintf(
                            '%s: %d days, %.2f h total, %.2f h geofence, %.2f h unknown, deleted %d, written %d',
                            (string) ($item->registration_number ?: $item->name),
                            $result['days'],
                            $result['total_hours'],
                            $result['geofence_hours'],
                            $result['unknown_hours'],
                            $result['deleted_rows'],
                            $result['written_rows'],
                        ));
                    } catch (MissingMonthlyObjectReportTable $exception) {
                        $skipped++;
                        $this->warn(sprintf(
                            '%s skipped: %s',
                            (string) ($item->registration_number ?: $item->name),
                            $exception->getMessage(),
                        ));
                    } catch (Throwable $exception) {
                        if ($this->isSkippableUnitReportFailure($exception)) {
                            $skipped++;
                            $this->warn(sprintf(
                                '%s skipped: %s',
                                (string) ($item->registration_number ?: $item->name),
                                $exception->getMessage(),
                            ));

                            continue;
                        }

                        $failed++;
                        $this->error(sprintf(
                            '%s failed: %s',
                            (string) ($item->registration_number ?: $item->name),
                            $exception->getMessage(),
                        ));
                    } finally {
                        $processedUnits++;
                        $this->heartbeatHistoricalTask($progressTaskId, $processedUnits);

                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                }
            }, 'equipments.id', 'id');

        $this->table(['Metric', 'Value'], [
            ['Processed units', $ok],
            ['Skipped units', $skipped],
            ['Failed units', $failed],
            ['Deleted fact rows', $deleted],
            ['Written fact rows', $written],
            ['Unit chunk size', $unitChunk],
            ['Write batch size', $flushRows],
            ['Allowed types', implode(', ', $this->allowedTypeLabels())],
            ['Allowed Wialon group', $this->allowedWialonUnitGroupLabel()],
            ['Source report', (string) $template['name']],
        ]);

        return $failed > 0 || ($ok === 0 && $skipped > 0) ? self::FAILURE : self::SUCCESS;
    }

    private function heartbeatHistoricalTask(int $taskId, int $processedUnits): void
    {
        if ($taskId <= 0) {
            return;
        }

        DB::table('historical_recalculation_tasks')
            ->where('id', $taskId)
            ->where('status', 'running')
            ->update([
                'equipment_count' => $processedUnits,
                'last_heartbeat_at' => now(config('app.timezone')),
                'updated_at' => now(config('app.timezone')),
            ]);
    }

    /** @return array{resource_id:int, template_id:int, name:string} */
    private function resolveUnitReportTemplate(WialonService $wialon): array
    {
        $name = trim((string) config('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Report for Aylıq effektivlik'));
        $candidates = collect([$name, $name.' (unit)'])->filter()->unique()->values();

        foreach ($candidates as $candidate) {
            $local = WialonReportTemplate::query()
                ->where('name', $candidate)
                ->where('report_type', 'avl_unit')
                ->where(function (Builder $query): void {
                    $query->where('is_active', true)->orWhereNull('is_active');
                })
                ->first();

            if ($local) {
                return [
                    'resource_id' => (int) $local->resource_id,
                    'template_id' => (int) $local->wialon_template_id,
                    'name' => (string) $local->name,
                ];
            }
        }

        foreach ($candidates as $candidate) {
            $live = $wialon->findReportTemplateByName(null, $candidate);

            if (! is_array($live)) {
                continue;
            }

            if (($live['type'] ?? null) !== 'avl_unit') {
                continue;
            }

            return [
                'resource_id' => (int) ($live['resource_id'] ?? config('fleet.wialon.efficiency_report_resource_id')),
                'template_id' => (int) ($live['id'] ?? 0),
                'name' => (string) ($live['name'] ?? $candidate),
            ];
        }

        throw new RuntimeException('Individual Wialon report template for Aylıq effektivlik was not found. Expected avl_unit report.');
    }

    private function equipmentQuery(): Builder
    {
        return Equipment::query()
            ->select([
                'equipments.id',
                'equipments.name',
                'equipments.registration_number',
                'equipments.wialon_unit_id',
                DB::raw('COALESCE(direct_project_groups.project_id, matched_project_groups.project_id) as project_id'),
                DB::raw('COALESCE(direct_project_groups.id, matched_project_groups.id) as project_wialon_group_id'),
                DB::raw('COALESCE(direct_project_groups.wialon_group_id, matched_project_groups.wialon_group_id) as wialon_group_id'),
                DB::raw('COALESCE(direct_project_groups.ownership_type, matched_project_groups.ownership_type, equipments.ownership_type) as ownership_type'),
                'equipment_types.name as vehicle_type',
            ])
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->leftJoin('project_wialon_groups as direct_project_groups', 'direct_project_groups.id', '=', 'equipments.project_wialon_group_id')
            ->leftJoin('project_wialon_groups as matched_project_groups', function ($join): void {
                $join->on('matched_project_groups.wialon_group_id', '=', 'equipments.matched_wialon_group_id')
                    ->whereNull('equipments.project_wialon_group_id');
            })
            ->whereNotNull('equipments.wialon_unit_id')
            ->where('equipments.wialon_unit_id', '<>', '')
            ->whereIn('equipment_types.name', $this->allowedTypeLabels())
            ->where(function (Builder $query): void {
                $this->applyActiveProjectGroup($query, 'direct_project_groups');
                $query->orWhere(function (Builder $query): void {
                    $query->whereNull('equipments.project_wialon_group_id');
                    $this->applyActiveProjectGroup($query, 'matched_project_groups');
                });
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('wialon_unit_group_members')
                    ->join('wialon_unit_groups', 'wialon_unit_groups.id', '=', 'wialon_unit_group_members.wialon_unit_group_id')
                    ->whereColumn('wialon_unit_group_members.wialon_unit_item_id', 'equipments.wialon_unit_id')
                    ->where('wialon_unit_groups.is_active', true)
                    ->where(function ($query): void {
                        $groupId = trim((string) config('fleet.wialon.monthly_efficiency_unit_group_id', ''));
                        $groupName = trim((string) config('fleet.wialon.monthly_efficiency_unit_group_name', 'Bulldozer, Excavator, Dump Truck'));

                        if ($groupId !== '') {
                            $query->where('wialon_unit_groups.wialon_group_id', $groupId);
                        }

                        if ($groupName !== '') {
                            $method = $groupId !== '' ? 'orWhereRaw' : 'whereRaw';
                            $query->{$method}('LOWER(wialon_unit_groups.name) = ?', [mb_strtolower($groupName)]);
                        }
                    });
            })
            ->visibleInDashboard();
    }

    private function applyActiveProjectGroup(Builder $query, string $alias): void
    {
        $query->whereNotNull($alias.'.id')
            ->whereExists(function ($query) use ($alias): void {
                $query->selectRaw('1')
                    ->from('projects')
                    ->whereColumn('projects.id', $alias.'.project_id')
                    ->where('projects.active', true);
            });

        if (Schema::hasColumn('project_wialon_groups', 'is_active')) {
            $query->where($alias.'.is_active', true);
        }
    }

    private function projectOption(): int|false|null
    {
        $project = trim((string) $this->option('project'));

        if ($project === '') {
            return null;
        }

        if (! ctype_digit($project) || (int) $project <= 0) {
            return false;
        }

        return (int) $project;
    }

    private function applyProjectFilter(Builder $query, int $projectId): Builder
    {
        return $query->where(function (Builder $query) use ($projectId): void {
            $query->where('direct_project_groups.project_id', $projectId)
                ->orWhere(function (Builder $query) use ($projectId): void {
                    $query->whereNull('equipments.project_wialon_group_id')
                        ->where('matched_project_groups.project_id', $projectId);
                });
        });
    }

    private function allowedWialonUnitGroupLabel(): string
    {
        $groupId = trim((string) config('fleet.wialon.monthly_efficiency_unit_group_id', ''));
        $groupName = trim((string) config('fleet.wialon.monthly_efficiency_unit_group_name', 'Bulldozer, Excavator, Dump Truck'));

        if ($groupId !== '') {
            $catalogName = WialonUnitGroup::query()
                ->where('wialon_group_id', $groupId)
                ->value('name');

            return $catalogName ? $catalogName.' / '.$groupId : $groupId;
        }

        return $groupName;
    }

    private function isSkippableUnitReportFailure(Throwable $exception): bool
    {
        if ($exception instanceof MissingMonthlyObjectReportTable) {
            return true;
        }

        if (! $exception instanceof RuntimeException) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'Wialon API error')
            || str_contains($message, 'report result')
            || str_contains($message, 'Report result');
    }

    /** @return array<int, string> */
    private function allowedTypeLabels(): array
    {
        return collect(FleetVehicleType::MONTHLY_OBJECT_EFFICIENCY_TYPES)
            ->map(fn (string $type): string => FleetVehicleType::label($type))
            ->all();
    }

    /** @return array{days:int,total_hours:float,geofence_hours:float,unknown_hours:float,deleted_rows:int,written_rows:int} */
    private function syncOneUnit(WialonService $wialon, array $template, object $equipment, CarbonImmutable $from, CarbonImmutable $to, int $flushRows): array
    {
        $sid = $wialon->getSessionId();
        $wialon->cleanupReportResult($sid);

        try {
            $result = $wialon->executeReport(
                $template['resource_id'],
                $template['template_id'],
                $equipment->wialon_unit_id,
                $from->timestamp,
                $to->timestamp,
                (int) config('fleet.wialon.engine_hours_report_interval_flags', 0),
                $sid,
                false,
                max(30, (int) config('fleet.wialon.efficiency_report_timeout', 90)),
            );

            $tables = $result['reportResult']['tables'] ?? [];
            $engineTable = $this->findTable($tables, 'engine');
            $geofenceTable = $this->findTable($tables, 'geofence');

            if ($engineTable === null) {
                throw new MissingMonthlyObjectReportTable('Required Engine hours table is missing in report result.');
            }

            $totals = $this->engineRowsByDate($wialon, $sid, $engineTable['index'], $engineTable['table']);
            $geofences = $geofenceTable === null
                ? []
                : $this->geofenceRowsByDate($wialon, $sid, $geofenceTable['index'], $geofenceTable['table']);
            $unknownLabel = (string) config('fleet.wialon.monthly_efficiency_unknown_label', 'Naməlum');
            $rowsToWrite = [];
            $writtenRows = 0;
            $totalHours = 0.0;
            $geofenceHours = 0.0;
            $unknownHours = 0.0;
            $deletedRows = 0;

            foreach ($totals as $date => $total) {
                $knownForDate = collect($geofences[$date] ?? []);
                $knownForDate = $this->normalizeKnownSegments($knownForDate->all(), (float) $total['hours']);
                $knownHours = round((float) $knownForDate->sum('hours'), 2);
                $knownMileage = round((float) $knownForDate->sum('mileage'), 2);
                $unknown = max(0.0, round((float) $total['hours'] - $knownHours, 2));
                $unknownMileage = max(0.0, round((float) $total['mileage'] - $knownMileage, 2));

                $rowsToWrite[] = $this->factRow($equipment, $template, $date, self::SEGMENT_TOTAL, 'Total', $total);

                foreach ($knownForDate as $item) {
                    $rowsToWrite[] = $this->factRow($equipment, $template, $date, self::SEGMENT_GEOFENCE, (string) $item['geofence'], $item);
                }

                $rowsToWrite[] = $this->factRow($equipment, $template, $date, self::SEGMENT_UNKNOWN, $unknownLabel, [
                    'hours' => $unknown,
                    'seconds' => (int) round($unknown * 3600),
                    'mileage' => $unknownMileage,
                    'visits' => 0,
                    'started_at' => null,
                    'ended_at' => null,
                    'raw' => ['calculated' => true, 'total_hours' => $total['hours'], 'geofence_hours' => $knownHours],
                ]);

                $totalHours += (float) $total['hours'];
                $geofenceHours += $knownHours;
                $unknownHours += $unknown;
            }

            DB::transaction(function () use ($equipment, $template, $from, $to, $flushRows, $rowsToWrite, &$deletedRows, &$writtenRows): void {
                $deletedRows = $this->option('force')
                    ? $this->purgeExistingFacts($equipment, $template, $from, $to)
                    : 0;

                foreach (array_chunk($rowsToWrite, $flushRows) as $rows) {
                    $writtenRows += $this->upsertFacts($rows);
                }
            });

            return [
                'days' => count($totals),
                'total_hours' => round($totalHours, 2),
                'geofence_hours' => round($geofenceHours, 2),
                'unknown_hours' => round($unknownHours, 2),
                'deleted_rows' => $deletedRows,
                'written_rows' => $writtenRows,
            ];
        } finally {
            $wialon->cleanupReportResult($sid);
        }
    }

    private function purgeExistingFacts(object $equipment, array $template, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::table('monthly_efficiency_unit_geofence_facts')
            ->where('wialon_unit_id', (string) $equipment->wialon_unit_id)
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('source_report_name', $this->sourceReportNames($template))
            ->delete();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function upsertFacts(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table('monthly_efficiency_unit_geofence_facts')->upsert(
            $rows,
            ['stat_date', 'wialon_unit_id', 'segment_type', 'geofence_name', 'source_report_name'],
            ['equipment_id', 'project_id', 'project_wialon_group_id', 'wialon_group_id', 'unit_name', 'registration_number', 'vehicle_type', 'ownership_type', 'engine_hours_decimal', 'engine_seconds', 'mileage_km', 'visits_count', 'started_at', 'ended_at', 'source_report_template_id', 'raw_row_json', 'updated_at'],
        );

        return count($rows);
    }

    /** @return array<int, string> */
    private function sourceReportNames(array $template): array
    {
        $configured = trim((string) config('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Report for Aylıq effektivlik'));

        return collect([
            $template['name'] ?? null,
            $configured,
            $configured !== '' ? $configured.' (unit)' : null,
        ])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function findTable(array $tables, string $kind): ?array
    {
        foreach ($tables as $index => $table) {
            $text = mb_strtolower(implode(' ', array_filter([
                $table['name'] ?? '',
                $table['label'] ?? '',
                ...($table['header'] ?? []),
                ...($table['header_type'] ?? []),
            ])));

            $isGeofence = str_contains($text, 'geofence') || str_contains($text, 'zone') || str_contains($text, 'geozon');

            if ($kind === 'engine' && str_contains($text, 'engine') && ! $isGeofence) {
                return ['index' => (int) $index, 'table' => $table];
            }

            if ($kind === 'geofence' && $isGeofence && str_contains($text, 'engine')) {
                return ['index' => (int) $index, 'table' => $table];
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function engineRowsByDate(WialonService $wialon, string $sid, int $tableIndex, array $table): array
    {
        $rows = $wialon->getReportResultRows($tableIndex, 0, max(0, (int) ($table['rows'] ?? 0) - 1), $sid);
        $hoursIndex = $this->columnIndex($table, ['engine hours'], ['duration'], 3);
        $mileageIndex = $this->columnIndex($table, ['mileage'], ['mileage', 'correct_mileage'], 7);
        $beginIndex = $this->columnIndex($table, ['beginning', 'start'], ['time_begin'], 8);
        $endIndex = $this->columnIndex($table, ['end'], ['time_end'], 9);
        $result = [];

        foreach ($rows as $row) {
            $cells = $row['c'] ?? [];
            $date = $this->dateFromCell($cells[0] ?? null);

            if ($date === null) {
                continue;
            }

            $seconds = $this->durationSeconds($cells[$hoursIndex] ?? null);
            $result[$date] = [
                'hours' => round($seconds / 3600, 2),
                'seconds' => $seconds,
                'mileage' => $this->mileageKm($cells[$mileageIndex] ?? null),
                'visits' => 0,
                'started_at' => $this->dateTimeText($cells[$beginIndex] ?? null, $date),
                'ended_at' => $this->dateTimeText($cells[$endIndex] ?? null, $date),
                'raw' => $row,
            ];
        }

        return $result;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function geofenceRowsByDate(WialonService $wialon, string $sid, int $tableIndex, array $table): array
    {
        $parentRows = $wialon->getReportResultRows($tableIndex, 0, max(0, (int) ($table['rows'] ?? 0) - 1), $sid);
        $nameIndex = $this->columnIndex($table, ['name'], ['zone_name'], 1);
        $hoursIndex = $this->columnIndex($table, ['engine hours'], ['duration_in', 'duration'], 4);
        $mileageIndex = $this->columnIndex($table, ['mileage'], ['mileage'], 5);
        $visitsIndex = $this->columnIndex($table, ['visits'], ['visits_count'], 6);
        $beginIndex = $this->columnIndex($table, ['entry time', 'beginning'], ['time_begin'], 2);
        $endIndex = $this->columnIndex($table, ['exit time', 'end'], ['time_end'], 3);
        $result = [];

        foreach ($parentRows as $rowIndex => $parentRow) {
            $date = $this->dateFromCell(($parentRow['c'] ?? [])[0] ?? null);

            if ($date === null) {
                continue;
            }

            foreach ($wialon->getReportResultSubrows($tableIndex, (int) $rowIndex, $sid) as $subrow) {
                $cells = $subrow['c'] ?? [];
                $name = $this->cellText($cells[$nameIndex] ?? null);

                if ($name === '') {
                    $name = $this->cellText($cells[0] ?? null);
                }

                if ($name === '') {
                    continue;
                }

                $seconds = $this->durationSeconds($cells[$hoursIndex] ?? null);
                $result[$date][] = [
                    'geofence' => $name,
                    'hours' => round($seconds / 3600, 2),
                    'seconds' => $seconds,
                    'mileage' => $this->mileageKm($cells[$mileageIndex] ?? null),
                    'visits' => (int) round($this->numberValue($cells[$visitsIndex] ?? 0)),
                    'started_at' => $this->dateTimeText($cells[$beginIndex] ?? null, $date),
                    'ended_at' => $this->dateTimeText($cells[$endIndex] ?? null, $date),
                    'raw' => $subrow,
                ];
            }
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $segments */
    private function normalizeKnownSegments(array $segments, float $totalHours): Collection
    {
        $knownHours = round((float) collect($segments)->sum('hours'), 2);

        if ($knownHours <= 0.0 || $knownHours <= round($totalHours, 2)) {
            return collect($segments);
        }

        $ratio = max(0.0, $totalHours) / $knownHours;

        return collect($segments)->map(function (array $segment) use ($ratio, $knownHours, $totalHours): array {
            $originalHours = (float) ($segment['hours'] ?? 0.0);
            $adjustedHours = round($originalHours * $ratio, 2);

            $segment['hours'] = $adjustedHours;
            $segment['seconds'] = (int) round($adjustedHours * 3600);
            $segment['raw']['normalized_to_total'] = true;
            $segment['raw']['original_hours'] = $originalHours;
            $segment['raw']['known_hours_before_normalization'] = $knownHours;
            $segment['raw']['total_hours'] = $totalHours;

            return $segment;
        });
    }

    private function columnIndex(array $table, array $headers, array $headerTypes, int $default): int
    {
        $headers = array_map(fn (string $value): string => mb_strtolower($value), $headers);
        $headerTypes = array_map(fn (string $value): string => mb_strtolower($value), $headerTypes);

        foreach (($table['header'] ?? []) as $index => $header) {
            $text = mb_strtolower(trim((string) $header));

            if (collect($headers)->contains(fn (string $needle): bool => $text === $needle || str_contains($text, $needle))) {
                return (int) $index;
            }
        }

        foreach (($table['header_type'] ?? []) as $index => $type) {
            if (in_array(mb_strtolower(trim((string) $type)), $headerTypes, true)) {
                return (int) $index;
            }
        }

        return $default;
    }

    private function factRow(object $equipment, array $template, string $date, string $segment, string $geofence, array $data): array
    {
        $now = now();

        return [
            'stat_date' => $date,
            'equipment_id' => (int) $equipment->id,
            'project_id' => $equipment->project_id ? (int) $equipment->project_id : null,
            'project_wialon_group_id' => $equipment->project_wialon_group_id ? (int) $equipment->project_wialon_group_id : null,
            'wialon_group_id' => $equipment->wialon_group_id ?: null,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => (string) $equipment->name,
            'registration_number' => $equipment->registration_number ?: null,
            'vehicle_type' => (string) $equipment->vehicle_type,
            'ownership_type' => $equipment->ownership_type ?: null,
            'segment_type' => $segment,
            'geofence_name' => $geofence,
            'engine_hours_decimal' => round((float) ($data['hours'] ?? 0), 2),
            'engine_seconds' => (int) ($data['seconds'] ?? round(((float) ($data['hours'] ?? 0)) * 3600)),
            'mileage_km' => round((float) ($data['mileage'] ?? 0), 2),
            'visits_count' => (int) ($data['visits'] ?? 0),
            'started_at' => $data['started_at'] ?? null,
            'ended_at' => $data['ended_at'] ?? null,
            'source_report_template_id' => (int) $template['template_id'],
            'source_report_name' => (string) $template['name'],
            'raw_row_json' => json_encode($data['raw'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function dateFromCell(mixed $cell): ?string
    {
        $text = $this->cellText($cell);

        if (preg_match('/\d{4}-\d{2}-\d{2}/', $text, $matches)) {
            return $matches[0];
        }

        try {
            return CarbonImmutable::parse($text, config('app.timezone'))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTimeText(mixed $cell, string $date): ?string
    {
        if (is_array($cell) && is_numeric($cell['v'] ?? null)) {
            return CarbonImmutable::createFromTimestamp((int) $cell['v'], 'UTC')
                ->timezone(config('app.timezone'))
                ->toDateTimeString();
        }

        $text = $this->cellText($cell);

        if ($text === '' || in_array($text, ['-', '-----'], true)) {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $text)) {
            return $date.' '.$text;
        }

        return $text;
    }

    private function durationSeconds(mixed $cell): int
    {
        if (is_array($cell)) {
            $textSeconds = $this->durationSeconds($cell['t'] ?? null);

            return $textSeconds > 0 ? $textSeconds : $this->durationSeconds($cell['v'] ?? null);
        }

        if (is_int($cell) || is_float($cell)) {
            $value = (float) $cell;

            return max(0, (int) round(($value <= 744.0 ? $value * 3600 : $value)));
        }

        $value = trim((string) $cell);

        if ($value === '' || in_array($value, ['-', '-----'], true)) {
            return 0;
        }

        if (preg_match('/^(?:(\d+)\s+day[s]?\s+)?(\d+):(\d{2})(?::(\d{2}))?$/i', $value, $matches)) {
            $days = (int) ($matches[1] ?? 0);
            $hours = (int) $matches[2];
            $minutes = (int) $matches[3];
            $seconds = (int) ($matches[4] ?? 0);

            return max(0, (($days * 24 + $hours) * 3600) + ($minutes * 60) + $seconds);
        }

        return max(0, (int) round($this->numberValue($value) * 3600));
    }

    private function mileageKm(mixed $cell): float
    {
        return max(0.0, $this->numberValue($cell));
    }

    private function numberValue(mixed $cell): float
    {
        if (is_array($cell)) {
            $cell = $cell['v'] ?? $cell['t'] ?? 0;
        }

        if (is_numeric($cell)) {
            return (float) $cell;
        }

        $normalized = preg_replace('/[^\d,.\-]+/u', '', str_replace(["\xc2\xa0", ' '], '', trim((string) $cell))) ?? '';

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            $normalized = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $normalized))
                : str_replace(',', '', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        return preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches)
            ? (float) $matches[0]
            : 0.0;
    }

    private function cellText(mixed $cell): string
    {
        if (is_array($cell)) {
            $cell = $cell['t'] ?? $cell['v'] ?? '';
        }

        return trim((string) $cell);
    }
}

class MissingMonthlyObjectReportTable extends RuntimeException
{
}
