<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Support\DashboardDateRangePolicy;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardDailyAverageService
{
    public function __construct(private DashboardDateRangePolicy $dateRangePolicy) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function chartData(array $filters): array
    {
        return [
            'engine_hours' => $this->dashboardData($filters, 'engine_hours')['chart'],
            'mileage' => $this->dashboardData($filters, 'mileage')['chart'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboardData(array $filters, string $metric): array
    {
        $filters = $this->normalizedFilters($filters);
        $summary = $this->typeSummary($filters, $metric);
        $typeRows = $this->typeComparisonRows($summary, $metric);
        $chart = $this->typeChartData($typeRows);
        $daysCount = $this->daysCount($filters['from'], $filters['to']);

        return [
            'metric' => $metric,
            'unit' => $metric === 'mileage' ? 'km' : 'saat',
            'days_count' => $daysCount,
            'allowed_types' => collect($this->allowedTypes($metric))
                ->map(fn (string $type): array => [
                    'code' => $type,
                    'slug' => FleetVehicleType::slug($type),
                    'label' => FleetVehicleType::label($type),
                ])
                ->values()
                ->all(),
            'chart' => $chart,
            'type_rows' => $typeRows,
            'summary_rows' => $summary->values()->all(),
            'kpis' => [],
            'table_rows' => [],
            'day_cards' => [],
            'has_data' => $chart['has_data'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function typeSummary(array $filters, string $metric): Collection
    {
        $filters = $this->normalizedFilters($filters);
        $daysCount = $this->daysCount($filters['from'], $filters['to']);
        $equipment = $this->eligibleEquipment($filters, $metric);
        $aggregates = $this->metricAggregatesByEquipment($filters, $metric, $equipment->pluck('id')->all());
        $typeCodes = $filters['vehicle_types'] === []
            ? $this->allowedTypes($metric)
            : array_values(array_intersect($this->allowedTypes($metric), $filters['vehicle_types']));
        $rows = collect();

        foreach ($typeCodes as $typeCode) {
            foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownership) {
                if ($filters['ownership_type'] && $filters['ownership_type'] !== $ownership) {
                    continue;
                }

                $units = $equipment
                    ->filter(fn (Equipment $item): bool => $item->ownership_type === $ownership && $this->typeCode($item->type?->name) === $typeCode)
                    ->values();
                $unitIdsWithData = $units
                    ->filter(fn (Equipment $unit): bool => (int) ($aggregates->get($unit->id)['data_days'] ?? 0) > 0)
                    ->pluck('id')
                    ->all();
                $unitsWithData = array_fill_keys($unitIdsWithData, true);
                $total = $units->sum(fn (Equipment $unit): float => (float) ($aggregates->get($unit->id)['total_value'] ?? 0.0));

                if ($filters['data_status'] === 'available') {
                    $units = $units->filter(fn (Equipment $unit): bool => isset($unitsWithData[$unit->id]))->values();
                    $total = $units->sum(fn (Equipment $unit): float => (float) ($aggregates->get($unit->id)['total_value'] ?? 0.0));
                    $unitsWithData = array_fill_keys($units->pluck('id')->all(), true);
                } elseif ($filters['data_status'] === 'missing') {
                    $units = $units->reject(fn (Equipment $unit): bool => isset($unitsWithData[$unit->id]))->values();
                    $total = 0.0;
                    $unitsWithData = [];
                }

                $unitsCount = $units->count();
                $average = $unitsCount > 0 ? round($total / $unitsCount / $daysCount, 2) : null;

                $rows->push([
                    'vehicle_type' => FleetVehicleType::label($typeCode),
                    'type_code' => $typeCode,
                    'type_slug' => FleetVehicleType::slug($typeCode),
                    'ownership' => $ownership,
                    'ownership_label' => $this->ownershipLabel($ownership),
                    'total_value' => round($total, 2),
                    'units_count' => $unitsCount,
                    'days_count' => $daysCount,
                    'average_per_unit_per_day' => $average,
                    'units_without_data' => max(0, $unitsCount - count($unitsWithData)),
                    'has_units' => $unitsCount > 0,
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function summaryRows(array $filters, string $metric): array
    {
        return $this->typeSummary($filters, $metric)
            ->map(fn (array $row): array => [
                $row['vehicle_type'],
                $row['ownership_label'],
                $this->formatMetricValue((float) $row['total_value'], $metric),
                $row['units_count'],
                $row['days_count'],
                $this->formatNullableMetric($row['average_per_unit_per_day'], $metric),
                $row['units_without_data'],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function summaryColumns(string $metric): array
    {
        return [
            'Texnika növü',
            'Mənsubiyyət',
            $metric === 'mileage' ? 'Ümumi yürüş' : 'Ümumi motosaat',
            'Texnika sayı',
            'Gün sayı',
            'Orta gündəlik göstərici',
            'Məlumatsız texnika',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function journalExportRows(array $filters, string $metric): array
    {
        return $this->journalRows($filters, $metric)
            ->values()
            ->map(function (array $row, int $index): array {
                return [
                    $index + 1,
                    $row['date'],
                    $row['name'],
                    $row['registration_number'],
                    $row['project'],
                    $row['ownership'],
                    $row['vehicle_type'],
                    $row['engine_hours'] === null ? '' : round((float) $row['engine_hours'], 2),
                    $row['mileage'] === null ? '' : round((float) $row['mileage'], 1),
                    $row['data_status'],
                    $row['wialon_id'],
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateJournal(array $filters, string $metric): LengthAwarePaginator
    {
        $rawFilters = $filters;
        $filters = $this->normalizedFilters($filters);
        $page = max(1, (int) ($rawFilters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($rawFilters['per_page'] ?? 50)));
        $result = $this->paginatedJournalRows($filters, $metric, $page, $perPage);

        return new LengthAwarePaginator(
            $result['rows'],
            $result['total'],
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateGrouped(array $filters, string $metric, string $groupBy): LengthAwarePaginator
    {
        $rows = $this->groupRows($filters, $metric, $groupBy);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<string, string>
     */
    public function journalColumns(string $metric): array
    {
        return [
            'number' => '№',
            'date' => 'Tarix',
            'name' => 'Texnikanın adı',
            'registration_number' => 'Qeydiyyat nişanı',
            'project' => 'Layihə',
            'ownership' => 'Mənsubiyyət',
            'vehicle_type' => 'Texnika növü',
            'engine_hours' => 'Motosaat',
            'mileage' => 'Yürüş',
            'data_status' => 'Məlumat statusu',
            'wialon_id' => 'Wialon ID',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnsForGroup(string $metric, string $groupBy): array
    {
        return match ($groupBy) {
            'day' => [
                'number' => '№',
                'date' => 'Tarix',
                'total_value' => $metric === 'mileage' ? 'Ümumi yürüş' : 'Ümumi motosaat',
                'units_count' => 'Texnika sayı',
                'average_value' => 'Orta göstərici',
                'data_days' => 'Məlumat var',
                'missing_days' => 'Məlumat yoxdur',
            ],
            'unit' => [
                'number' => '№',
                'name' => 'Texnikanın adı',
                'registration_number' => 'Qeydiyyat nişanı',
                'project' => 'Layihə',
                'ownership' => 'Mənsubiyyət',
                'vehicle_type' => 'Texnika növü',
                'total_value' => $metric === 'mileage' ? 'Ümumi yürüş' : 'Ümumi motosaat',
                'average_value' => 'Orta/gün',
                'data_days' => 'Məlumatlı gün',
                'missing_days' => 'Məlumatsız gün',
                'wialon_id' => 'Wialon ID',
            ],
            default => $this->journalColumns($metric),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function exportRowsForGroup(array $filters, string $metric, string $groupBy): array
    {
        if (! in_array($groupBy, ['day', 'unit'], true)) {
            return $this->journalExportRows($filters, $metric);
        }

        return $this->groupRows($filters, $metric, $groupBy)
            ->values()
            ->map(function (array $row, int $index) use ($groupBy): array {
                if ($groupBy === 'day') {
                    return [
                        $index + 1,
                        $row['date'],
                        $row['total_value'],
                        $row['units_count'],
                        $row['average_value'],
                        $row['data_days'],
                        $row['missing_days'],
                    ];
                }

                return [
                    $index + 1,
                    $row['name'],
                    $row['registration_number'],
                    $row['project'],
                    $row['ownership'],
                    $row['vehicle_type'],
                    $row['total_value'],
                    $row['average_value'],
                    $row['data_days'],
                    $row['missing_days'],
                    $row['wialon_id'],
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyAverages(array $filters, string $metric): Collection
    {
        $filters = $this->normalizedFilters($filters);
        $dates = $this->dates($filters['from'], $filters['to']);
        $equipment = $this->eligibleEquipment($filters, $metric);
        $stats = $this->statsFor($filters, $equipment->pluck('id')->all());
        $rows = collect();

        foreach ($dates as $date) {
            foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownership) {
                if ($filters['ownership_type'] && $filters['ownership_type'] !== $ownership) {
                    continue;
                }

                $ownershipEquipment = $equipment->where('ownership_type', $ownership);
                $sum = 0.0;
                $valid = 0;

                foreach ($ownershipEquipment as $item) {
                    $stat = $stats->get($date.'|'.$item->id);

                    if (! $this->hasValidData($stat)) {
                        continue;
                    }

                    $sum += $metric === 'mileage'
                        ? (float) $stat->distance_km
                        : (float) $stat->worked_hours;
                    $valid++;
                }

                $rows->push([
                    'date' => $date,
                    'ownership' => $ownership,
                    'average' => $valid > 0 ? round($sum / $valid, $metric === 'mileage' ? 0 : 1) : null,
                    'valid_units_count' => $valid,
                    'missing_units_count' => max(0, $ownershipEquipment->count() - $valid),
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function journalRows(array $filters, string $metric): Collection
    {
        $filters = $this->normalizedFilters($filters);
        $dates = $this->dates($filters['from'], $filters['to']);
        $equipment = $this->eligibleEquipment($filters, $metric);
        $stats = $this->statsFor($filters, $equipment->pluck('id')->all());
        $rows = collect();

        foreach ($dates as $date) {
            foreach ($equipment as $item) {
                $stat = $stats->get($date.'|'.$item->id);
                $hasRecord = $this->hasValidData($stat);
                $hasData = $this->statMetricValue($stat, $metric) !== null;

                $rows->push([
                    'date' => $date,
                    'name' => $item->name,
                    'registration_number' => $item->registration_number ?: '—',
                    'vehicle_type' => $this->displayTypeName($item->type?->name),
                    'vehicle_type_code' => $this->typeCode($item->type?->name),
                    'ownership' => $this->ownershipLabel($item->ownership_type),
                    'ownership_type' => $item->ownership_type,
                    'project_id' => $item->project_id,
                    'project' => $item->project?->name ?: '—',
                    'engine_hours' => $hasRecord ? (float) $stat->worked_hours : null,
                    'mileage' => $hasRecord && (float) $stat->distance_km >= 0 ? (float) $stat->distance_km : null,
                    'data_available' => $hasData,
                    'data_status' => $hasData ? 'Məlumat var' : 'Məlumat yoxdur',
                    'wialon_id' => $item->wialon_unit_id,
                ]);
            }
        }

        return $rows
            ->when($filters['data_status'] !== 'all', function (Collection $rows) use ($filters): Collection {
                return $rows->filter(fn (array $row): bool => $filters['data_status'] === 'available'
                    ? (bool) $row['data_available']
                    : ! (bool) $row['data_available']);
            })
            ->when($filters['unit_name'] !== '', function (Collection $rows) use ($filters): Collection {
                $needle = Str::lower($filters['unit_name']);

                return $rows->filter(fn (array $row): bool => Str::contains(Str::lower((string) $row['name']), $needle));
            })
            ->when($filters['registration_number'] !== '', function (Collection $rows) use ($filters): Collection {
                $needle = Str::lower($filters['registration_number']);

                return $rows->filter(fn (array $row): bool => Str::contains(Str::lower((string) $row['registration_number']), $needle));
            })
            ->when($filters['wialon_id'] !== '', function (Collection $rows) use ($filters): Collection {
                $needle = Str::lower($filters['wialon_id']);

                return $rows->filter(fn (array $row): bool => Str::contains(Str::lower((string) $row['wialon_id']), $needle));
            })
            ->when($filters['search'] !== '', function (Collection $rows) use ($filters): Collection {
                $search = Str::lower($filters['search']);

                return $rows->filter(fn (array $row): bool => Str::contains(Str::lower(implode(' ', [
                    $row['date'],
                    $row['name'],
                    $row['registration_number'],
                    $row['vehicle_type'],
                    $row['ownership'],
                    $row['project'],
                    $row['data_status'],
                    $row['wialon_id'],
                ])), $search));
            })
            ->pipe(fn (Collection $rows): Collection => $this->sortJournalRows($rows, $filters, $metric))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, total: int}
     */
    private function paginatedJournalRows(array $filters, string $metric, int $page, int $perPage): array
    {
        $dates = $this->dates($filters['from'], $filters['to']);
        $equipmentIds = $this->eligibleEquipment($filters, $metric)->pluck('id')->all();

        if ($dates === [] || $equipmentIds === []) {
            return ['rows' => collect(), 'total' => 0];
        }

        $query = $this->journalQuery($filters, $metric, $dates, $equipmentIds);
        $total = (clone $query)->count();
        $rows = $this->applyJournalQuerySorting($query, $filters, $metric)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (object $row): array => $this->databaseJournalRow($row, $metric))
            ->values();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $dates
     * @param  array<int, int>  $equipmentIds
     */
    private function journalQuery(array $filters, string $metric, array $dates, array $equipmentIds): QueryBuilder
    {
        [$dateSql, $dateBindings] = $this->dateSeriesTable($dates);
        $latestStats = EquipmentDailyStat::query()
            ->selectRaw('MAX(id) as id, equipment_id, stat_date')
            ->whereDate('stat_date', '>=', $filters['from'])
            ->whereDate('stat_date', '<=', $filters['to'])
            ->whereIn('equipment_id', $equipmentIds)
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['project_ids'], fn (Builder $query, array $projectIds) => $query->whereIn('project_id', $projectIds))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('ownership_type', $ownership))
            ->groupBy('equipment_id', 'stat_date');

        $query = DB::query()
            ->fromRaw('('.$dateSql.') as calendar', $dateBindings)
            ->crossJoin('equipments')
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->leftJoin('projects', 'projects.id', '=', 'equipments.project_id')
            ->leftJoinSub($latestStats, 'latest_stats', function ($join): void {
                $join->on('latest_stats.equipment_id', '=', 'equipments.id')
                    ->on('latest_stats.stat_date', '=', 'calendar.stat_date');
            })
            ->leftJoin('equipment_daily_stats as stats', 'stats.id', '=', 'latest_stats.id')
            ->whereIn('equipments.id', $equipmentIds)
            ->select([
                'calendar.stat_date as date',
                'equipments.id as equipment_id',
                'equipments.name',
                'equipments.registration_number',
                'equipments.ownership_type',
                'equipments.project_id',
                'equipments.wialon_unit_id',
                'equipment_types.name as type_name',
                'projects.name as project_name',
                'stats.id as stat_id',
                'stats.worked_hours',
                'stats.distance_km',
                'stats.calculation_status',
            ]);

        $this->applyJournalDataStatusFilter($query, $filters, $metric);

        return $query;
    }

    /**
     * @param  array<int, string>  $dates
     * @return array{0: string, 1: array<int, string>}
     */
    private function dateSeriesTable(array $dates): array
    {
        $sql = collect($dates)
            ->map(fn (): string => 'select ? as stat_date')
            ->implode(' union all ');

        return [$sql, array_values($dates)];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyJournalDataStatusFilter(QueryBuilder $query, array $filters, string $metric): void
    {
        if ($filters['data_status'] === 'all') {
            return;
        }

        $method = $filters['data_status'] === 'available' ? 'whereRaw' : 'whereRaw';
        $query->{$method}(
            ($filters['data_status'] === 'available' ? '' : 'not ').'('.$this->metricAvailableSql($metric).')'
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyJournalQuerySorting(QueryBuilder $query, array $filters, string $metric): QueryBuilder
    {
        $direction = $filters['direction'] === 'desc' ? 'desc' : 'asc';
        $sortExpression = match ($filters['sort']) {
            'name' => 'lower(equipments.name)',
            'registration_number' => "lower(coalesce(nullif(equipments.registration_number, ''), 'вЂ”'))",
            'vehicle_type' => 'lower(equipment_types.name)',
            'project' => "lower(coalesce(projects.name, 'вЂ”'))",
            'ownership' => 'lower(equipments.ownership_type)',
            'engine_hours' => $this->metricSortSql('engine_hours'),
            'mileage' => $this->metricSortSql('mileage'),
            'data_status' => 'case when '.$this->metricAvailableSql($metric)." then 'available' else 'missing' end",
            'wialon_id' => 'equipments.wialon_unit_id',
            default => 'calendar.stat_date',
        };

        return $query
            ->orderByRaw($sortExpression.' '.$direction)
            ->orderBy('calendar.stat_date', $direction)
            ->orderByRaw('lower(equipments.name) '.$direction);
    }

    private function metricSortSql(string $metric): string
    {
        $column = $metric === 'mileage' ? 'stats.distance_km' : 'stats.worked_hours';

        return 'coalesce(case when '.$this->metricAvailableSql($metric).' then '.$column.' end, -1)';
    }

    private function metricAvailableSql(string $metric): string
    {
        $valid = "(stats.id is not null and (stats.calculation_status is null or stats.calculation_status in ('success', 'ok', 'published')))";

        if ($metric === 'mileage') {
            return $valid.' and stats.distance_km >= 0';
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $equipmentIds
     * @return Collection<int, array{total_value: float, data_days: int}>
     */
    private function metricAggregatesByEquipment(array $filters, string $metric, array $equipmentIds): Collection
    {
        if ($equipmentIds === []) {
            return collect();
        }

        $latestStats = EquipmentDailyStat::query()
            ->selectRaw('MAX(id) as id, equipment_id, stat_date')
            ->whereDate('stat_date', '>=', $filters['from'])
            ->whereDate('stat_date', '<=', $filters['to'])
            ->whereIn('equipment_id', $equipmentIds)
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['project_ids'], fn (Builder $query, array $projectIds) => $query->whereIn('project_id', $projectIds))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('ownership_type', $ownership))
            ->groupBy('equipment_id', 'stat_date');

        $valueColumn = $metric === 'mileage' ? 'stats.distance_km' : 'stats.worked_hours';
        $availableSql = $this->metricAvailableSql($metric);

        return DB::query()
            ->fromSub($latestStats, 'latest_stats')
            ->join('equipment_daily_stats as stats', 'stats.id', '=', 'latest_stats.id')
            ->selectRaw('stats.equipment_id')
            ->selectRaw('SUM(CASE WHEN '.$availableSql.' THEN '.$valueColumn.' ELSE 0 END) as total_value')
            ->selectRaw('SUM(CASE WHEN '.$availableSql.' THEN 1 ELSE 0 END) as data_days')
            ->groupBy('stats.equipment_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->equipment_id => [
                    'total_value' => (float) $row->total_value,
                    'data_days' => (int) $row->data_days,
                ],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseJournalRow(object $row, string $metric): array
    {
        $hasRecord = $row->stat_id !== null
            && $this->isValidCalculationStatus($row->calculation_status);
        $metricValue = null;

        if ($hasRecord) {
            $metricValue = $metric === 'mileage'
                ? (float) $row->distance_km
                : (float) $row->worked_hours;

            if ($metric === 'mileage' && $metricValue < 0) {
                $metricValue = null;
            }
        }

        return [
            'date' => (string) $row->date,
            'name' => $row->name,
            'registration_number' => $row->registration_number ?: 'вЂ”',
            'vehicle_type' => $this->displayTypeName($row->type_name),
            'vehicle_type_code' => $this->typeCode($row->type_name),
            'ownership' => $this->ownershipLabel($row->ownership_type),
            'ownership_type' => $row->ownership_type,
            'project_id' => $row->project_id,
            'project' => $row->project_name ?: 'вЂ”',
            'engine_hours' => $hasRecord ? (float) $row->worked_hours : null,
            'mileage' => $hasRecord && (float) $row->distance_km >= 0 ? (float) $row->distance_km : null,
            'data_available' => $metricValue !== null,
            'data_status' => $metricValue !== null ? 'Məlumat var' : 'Məlumat yoxdur',
            'wialon_id' => $row->wialon_unit_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRows(array $filters, string $metric, string $groupBy): Collection
    {
        $filters = $this->normalizedFilters($filters);
        $rows = $this->journalRows($filters, $metric);
        $metricKey = $metric === 'mileage' ? 'mileage' : 'engine_hours';
        $daysCount = $this->daysCount($filters['from'], $filters['to']);

        if ($groupBy === 'day') {
            return $rows
                ->groupBy('date')
                ->map(function (Collection $items, string $date) use ($metricKey, $metric): array {
                    $total = $items->sum(fn (array $row): float => (float) ($row[$metricKey] ?? 0));
                    $unitsCount = $items->unique('wialon_id')->count();
                    $dataDays = $items->filter(fn (array $row): bool => $row[$metricKey] !== null)->count();
                    $missingDays = max(0, $items->count() - $dataDays);

                    return [
                        'date' => $date,
                        'total_value' => $this->formatMetricValue((float) $total, $metric),
                        'units_count' => $unitsCount,
                        'average_value' => $unitsCount > 0 ? $this->formatMetricValue((float) $total / $unitsCount, $metric) : __('app.no_data'),
                        'data_days' => $dataDays,
                        'missing_days' => $missingDays,
                    ];
                })
                ->sortBy('date')
                ->values();
        }

        if ($groupBy === 'unit') {
            return $rows
                ->groupBy(fn (array $row): string => (string) $row['wialon_id'])
                ->map(function (Collection $items) use ($metricKey, $metric, $daysCount): array {
                    $first = $items->first();
                    $total = $items->sum(fn (array $row): float => (float) ($row[$metricKey] ?? 0));
                    $dataDays = $items->filter(fn (array $row): bool => $row[$metricKey] !== null)->count();
                    $missingDays = max(0, $daysCount - $dataDays);

                    return [
                        'name' => $first['name'] ?? '',
                        'registration_number' => $first['registration_number'] ?? '—',
                        'project' => $first['project'] ?? '—',
                        'ownership' => $first['ownership'] ?? '—',
                        'vehicle_type' => $first['vehicle_type'] ?? '—',
                        'total_value' => $this->formatMetricValue((float) $total, $metric),
                        'average_value' => $this->formatMetricValue((float) $total / max(1, $daysCount), $metric),
                        'data_days' => $dataDays,
                        'missing_days' => $missingDays,
                        'wialon_id' => $first['wialon_id'] ?? '',
                    ];
                })
                ->sortBy('name')
                ->values();
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function metricChartData(array $filters, string $metric): array
    {
        return $this->metricChartDataFromRows($this->dailyAverages($filters, $metric), $metric);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function metricChartDataFromRows(Collection $rows, string $metric): array
    {
        $dates = $rows->pluck('date')->unique()->values();
        $sameMonth = $dates
            ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('Y-m'))
            ->unique()
            ->count() === 1;

        $series = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $validCounts = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $missingCounts = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        foreach ($dates as $date) {
            foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownership) {
                $row = $rows->first(fn (array $item): bool => $item['date'] === $date && $item['ownership'] === $ownership);
                $series[$ownership][] = $row['average'] ?? null;
                $validCounts[$ownership][] = (int) ($row['valid_units_count'] ?? 0);
                $missingCounts[$ownership][] = (int) ($row['missing_units_count'] ?? 0);
            }
        }

        return [
            'labels' => $dates
                ->map(fn (string $date): string => $sameMonth ? CarbonImmutable::parse($date)->format('j') : CarbonImmutable::parse($date)->format('d.m'))
                ->all(),
            'dates' => $dates->all(),
            'full_dates' => $dates
                ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('d.m.Y'))
                ->all(),
            'series' => $series,
            'valid_counts' => $validCounts,
            'missing_counts' => $missingCounts,
            'has_data' => collect($series)->flatten()->filter(fn ($value): bool => $value !== null)->isNotEmpty(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregateAverage(Collection $rows, string $metric): array
    {
        $weightedSum = 0.0;
        $valid = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $rowValid = (int) ($row['valid_units_count'] ?? 0);
            $valid += $rowValid;
            $missing += (int) ($row['missing_units_count'] ?? 0);

            if (($row['average'] ?? null) !== null && $rowValid > 0) {
                $weightedSum += (float) $row['average'] * $rowValid;
            }
        }

        return [
            'average' => $valid > 0 ? round($weightedSum / $valid, $metric === 'mileage' ? 0 : 1) : null,
            'valid_units_count' => $valid,
            'missing_units_count' => $missing,
        ];
    }

    private function dateLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('az')->translatedFormat('j M');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Equipment>
     */
    private function eligibleEquipment(array $filters, string $metric): Collection
    {
        $allowedTypes = $this->allowedTypes($metric);

        return Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->operationalDashboardProject()
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['project_ids'], fn (Builder $query, array $projectIds) => $query->whereIn('equipments.project_id', $projectIds))
            ->when($filters['equipment_type_id'], fn (Builder $query, int $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('equipments.ownership_type', $ownership))
            ->when($filters['unit_name'] !== '', fn (Builder $query) => $query->where('equipments.name', 'like', '%'.$filters['unit_name'].'%'))
            ->when($filters['registration_number'] !== '', fn (Builder $query) => $query->where('equipments.registration_number', 'like', '%'.$filters['registration_number'].'%'))
            ->when($filters['wialon_id'] !== '', fn (Builder $query) => $query->where('equipments.wialon_unit_id', 'like', '%'.$filters['wialon_id'].'%'))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('equipments.name', 'like', $search)
                        ->orWhere('equipments.registration_number', 'like', $search)
                        ->orWhere('equipments.wialon_unit_id', 'like', $search)
                        ->orWhereHas('type', fn (Builder $query) => $query->where('name', 'like', $search))
                        ->orWhereHas('project', fn (Builder $query) => $query->where('name', 'like', $search));
                });
            })
            ->get()
            ->filter(fn (Equipment $item): bool => in_array($this->typeCode($item->type?->name), $allowedTypes, true))
            ->filter(fn (Equipment $item): bool => $filters['vehicle_types'] === [] || in_array($this->typeCode($item->type?->name), $filters['vehicle_types'], true))
            ->values();
    }

    /**
     * @param  array<int, int>  $equipmentIds
     * @return Collection<string, EquipmentDailyStat>
     */
    private function statsFor(array $filters, array $equipmentIds): Collection
    {
        if ($equipmentIds === []) {
            return collect();
        }

        return EquipmentDailyStat::query()
            ->whereDate('stat_date', '>=', $filters['from'])
            ->whereDate('stat_date', '<=', $filters['to'])
            ->whereIn('equipment_id', $equipmentIds)
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['project_ids'], fn (Builder $query, array $projectIds) => $query->whereIn('project_id', $projectIds))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('ownership_type', $ownership))
            ->orderBy('id')
            ->get()
            ->keyBy(fn (EquipmentDailyStat $stat): string => $stat->stat_date->toDateString().'|'.$stat->equipment_id);
    }

    private function hasValidData(?EquipmentDailyStat $stat): bool
    {
        if (! $stat) {
            return false;
        }

        if (! $this->isValidCalculationStatus($stat->calculation_status)) {
            return false;
        }

        return true;
    }

    private function isValidCalculationStatus(?string $status): bool
    {
        return $status === null || in_array($status, ['success', 'ok', 'published'], true);
    }

    /**
     * @return array<int, string>
     */
    private function dates(string $from, string $to): array
    {
        return collect(CarbonPeriod::create($from, $to))
            ->map(fn ($date): string => $date->toDateString())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $filters): array
    {
        $range = $this->dateRangePolicy->normalize([
            ...$filters,
            '_default_from' => now(config('app.timezone'))->toDateString(),
            '_default_to' => $filters['from'] ?? $filters['date_from'] ?? now(config('app.timezone'))->toDateString(),
        ], 'modal');

        $ownership = $filters['ownership_type'] ?? null;
        if (! in_array($ownership, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $ownership = null;
        }

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'project_ids' => $this->integerArray($filters['project_ids'] ?? []),
            'equipment_type_id' => filled($filters['equipment_type_id'] ?? null) ? (int) $filters['equipment_type_id'] : null,
            'vehicle_types' => $this->vehicleTypes($filters['vehicle_types'] ?? []),
            'ownership_type' => $ownership,
            'data_status' => $this->dataStatus($filters['data_status'] ?? null),
            'unit_name' => trim((string) ($filters['unit_name'] ?? '')),
            'registration_number' => trim((string) ($filters['registration_number'] ?? '')),
            'wialon_id' => trim((string) ($filters['wialon_id'] ?? '')),
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort' => $this->sort($filters['sort'] ?? null),
            'direction' => ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedTypes(string $metric): array
    {
        return array_values(config(
            $metric === 'mileage'
                ? 'fleet_efficiency.average_mileage_vehicle_types'
                : 'fleet_efficiency.average_engine_hours_vehicle_types',
            []
        ));
    }

    private function typeCode(?string $type): string
    {
        return FleetVehicleType::normalize($type);
    }

    private function displayTypeName(?string $type): string
    {
        return FleetVehicleType::display($type);
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İCARƏ' : 'NWC';
    }

    private function formatMetricValue(float $value, string $metric): string
    {
        return $metric === 'mileage'
            ? number_format($value, 0, '.', ' ').' km'
            : number_format($value, 2).' saat';
    }

    private function formatNullableMetric(mixed $value, string $metric): string
    {
        return $value === null ? __('app.no_data') : $this->formatMetricValue((float) $value, $metric);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $summary
     * @return array<int, array<string, mixed>>
     */
    private function typeComparisonRows(Collection $summary, string $metric): array
    {
        return collect($this->allowedTypes($metric))
            ->map(function (string $typeCode) use ($summary): array {
                $byOwnership = $summary
                    ->where('type_code', $typeCode)
                    ->keyBy('ownership');

                return [
                    'vehicle_type' => FleetVehicleType::label($typeCode),
                    'type_code' => $typeCode,
                    'type_slug' => FleetVehicleType::slug($typeCode),
                    'nwc' => $byOwnership->get(Equipment::OWNERSHIP_NWC),
                    'icare' => $byOwnership->get(Equipment::OWNERSHIP_ICARE),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $typeRows
     * @return array<string, mixed>
     */
    private function typeChartData(array $typeRows): array
    {
        $labels = [];
        $series = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $totals = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $unitCounts = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $missingCounts = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $typeSlugs = [];

        foreach ($typeRows as $row) {
            $labels[] = $row['vehicle_type'];
            $typeSlugs[] = $row['type_slug'];

            foreach ([Equipment::OWNERSHIP_NWC => 'nwc', Equipment::OWNERSHIP_ICARE => 'icare'] as $ownership => $key) {
                $summary = $row[$key] ?? null;
                $series[$ownership][] = $summary['average_per_unit_per_day'] ?? null;
                $totals[$ownership][] = $summary['total_value'] ?? 0;
                $unitCounts[$ownership][] = $summary['units_count'] ?? 0;
                $missingCounts[$ownership][] = $summary['units_without_data'] ?? 0;
            }
        }

        return [
            'labels' => $labels,
            'type_slugs' => $typeSlugs,
            'series' => $series,
            'totals' => $totals,
            'unit_counts' => $unitCounts,
            'missing_counts' => $missingCounts,
            'has_data' => collect($series)->flatten()->filter(fn ($value): bool => $value !== null)->isNotEmpty(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function formulaSummary(array $filters, string $metric): ?array
    {
        $summary = $this->typeSummary($filters, $metric);

        if ($summary->count() !== 1) {
            return null;
        }

        $row = $summary->first();

        return [
            'metric' => $metric,
            'vehicle_type' => $row['vehicle_type'],
            'ownership' => $row['ownership_label'],
            'total_label' => $metric === 'mileage' ? 'Ümumi yürüş' : 'Ümumi motosaat',
            'total_value' => $this->formatMetricValue((float) $row['total_value'], $metric),
            'units_count' => (int) $row['units_count'],
            'days_count' => (int) $row['days_count'],
            'average_value' => $this->formatNullableMetric($row['average_per_unit_per_day'], $metric),
            'units_without_data' => (int) $row['units_without_data'],
        ];
    }

    private function statMetricValue(?EquipmentDailyStat $stat, string $metric): ?float
    {
        if (! $this->hasValidData($stat)) {
            return null;
        }

        $value = $metric === 'mileage'
            ? (float) $stat->distance_km
            : (float) $stat->worked_hours;

        if ($metric === 'mileage' && $value < 0) {
            return null;
        }

        return $value;
    }

    private function daysCount(string $from, string $to): int
    {
        return max(1, CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);
    }

    /**
     * @return array<int, int>
     */
    private function integerArray(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => filled($item))
            ->map(fn (mixed $item): int => (int) $item)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function vehicleTypes(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => filled($item))
            ->map(fn (mixed $item): string => FleetVehicleType::normalize((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    private function dataStatus(?string $status): string
    {
        return in_array($status, ['available', 'missing'], true) ? $status : 'all';
    }

    private function sort(?string $sort): string
    {
        return in_array($sort, [
            'date',
            'name',
            'registration_number',
            'vehicle_type',
            'project',
            'ownership',
            'engine_hours',
            'mileage',
            'data_status',
            'wialon_id',
        ], true) ? $sort : 'date';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sortJournalRows(Collection $rows, array $filters, string $metric): Collection
    {
        $sort = $this->sort($filters['sort'] ?? null);
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $rows
            ->sortBy(fn (array $row): array => [
                $this->journalSortValue($row, $sort, $metric),
                (string) $row['date'],
                mb_strtolower((string) $row['name']),
            ], SORT_REGULAR, $direction === 'desc')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function journalSortValue(array $row, string $sort, string $metric): mixed
    {
        return match ($sort) {
            'name' => mb_strtolower((string) $row['name']),
            'registration_number' => mb_strtolower((string) $row['registration_number']),
            'vehicle_type' => mb_strtolower((string) $row['vehicle_type']),
            'project' => mb_strtolower((string) $row['project']),
            'ownership' => mb_strtolower((string) $row['ownership']),
            'engine_hours' => (float) ($row['engine_hours'] ?? -1),
            'mileage' => (float) ($row['mileage'] ?? -1),
            'data_status' => mb_strtolower((string) $row['data_status']),
            'wialon_id' => (string) $row['wialon_id'],
            default => (string) $row['date'],
        };
    }
}
