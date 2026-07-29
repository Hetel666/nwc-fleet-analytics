<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Support\DashboardDateRangePolicy;
use App\Support\FleetVehicleType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FleetEfficiencyService
{
    public const DAY_STATUS_LESS_THAN_1 = 'less_than_1_hour';

    public const DAY_STATUS_LESS_THAN_7 = 'less_than_7_hours';

    public const DAY_STATUS_BETWEEN_7_AND_10 = 'between_7_and_10_hours';

    public const DAY_STATUS_OVER_10 = 'over_10_hours';

    public const LEGACY_DAY_STATUS_OVER_10 = 'over_10_day_hours';

    public const STATUS_OVERTIME = 'overtime';

    public const STATUS_NO_DATA = 'no_data';

    public function __construct(private DashboardDateRangePolicy $dateRangePolicy) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function projectRowsByOwnership(array $filters): array
    {
        $rows = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        foreach ($this->summaryAggregates($filters, byProject: true) as $row) {
            $ownership = $row['ownership_type'];
            $projectId = (int) $row['project_id'];
            $summary = $this->emptyProjectRow($projectId, (string) $row['project']);

            foreach ($this->aggregateKeys() as $key) {
                $summary[$key] = (int) $row[$key];
            }

            $summary['total'] = (int) ($row['total_rows'] ?? $this->daytimeTotal($summary));
            $rows[$ownership][$projectId] = $summary;
        }

        foreach ($rows as &$ownershipRows) {
            $ownershipRows = collect($ownershipRows)
                ->sortBy([
                    ['total', 'desc'],
                    ['name', 'asc'],
                ])
                ->values()
                ->all();
        }
        unset($ownershipRows);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function summaryForOwnership(array $filters, string $ownershipType): array
    {
        $summary = $this->emptySummary();
        $row = $this->summaryAggregates([...$filters, 'ownership_type' => $ownershipType], byProject: false)->first();

        if ($row !== null) {
            foreach ($this->aggregateKeys() as $key) {
                $summary[$key] = (int) $row[$key];
            }
        }

        $summary['total'] = (int) ($row['total_rows'] ?? $this->daytimeTotal($summary));

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function exportRows(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->filterDailyRows($this->dailyRows($filters), $filters)
            ->values()
            ->map(fn (array $row, int $index): array => $this->exportRow($row, $index + 1))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $query = $this->dailyRowsQuery($filters);
        $this->applyDailyRowFilters($query, $filters);
        $total = (clone $query)->count();
        $this->applyDailyRowSort($query, $filters);

        $rows = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): array => $this->dailyRowFromRecord($row))
            ->values()
            ->all();

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyRows(array $filters): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $dates = collect(iterator_to_array(CarbonPeriod::create($filters['from'], $filters['to'])))
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->values();
        $equipment = $this->eligibleEquipment($filters);

        if ($equipment->isEmpty() || $dates->isEmpty()) {
            return collect();
        }

        $stats = EquipmentDailyStat::query()
            ->whereIn('equipment_id', $equipment->pluck('id')->all())
            ->whereDate('stat_date', '>=', $filters['from'])
            ->whereDate('stat_date', '<=', $filters['to'])
            ->orderBy('id')
            ->get()
            ->keyBy(fn (EquipmentDailyStat $stat): string => $stat->equipment_id.'|'.$stat->stat_date->toDateString());

        $rows = collect();

        foreach ($equipment as $item) {
            foreach ($dates as $date) {
                $rows->push($this->dailyRow($item, $date, $stats->get($item->id.'|'.$date)));
            }
        }

        return $rows
            ->sortBy([
                ['date', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    public function daytimeStatusForHours(?float $hours): ?string
    {
        return $hours === null ? null : $this->statusForHours($hours);
    }

    public function efficiencyStatusForHours(?float $daytimeHours, ?float $totalHours): ?string
    {
        return $daytimeHours === null ? null : $this->statusForHours($daytimeHours);
    }

    public function statusLabelFor(string $status): string
    {
        return $this->statusLabel($this->dayStatus($status) ?? $this->workCategory($status) ?? $status);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterDailyRows(Collection $rows, array $filters): Collection
    {
        $category = $filters['work_category'] ?? null;
        $dayStatus = $filters['day_status'] ?? null;
        $dataStatus = $filters['data_status'] ?? 'all';
        $hasOvertime = $filters['has_overtime'] ?? 'all';
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $unitName = mb_strtolower(trim((string) ($filters['unit_name'] ?? '')));
        $registrationNumber = mb_strtolower(trim((string) ($filters['registration_number'] ?? '')));
        $wialonId = mb_strtolower(trim((string) ($filters['wialon_id'] ?? '')));
        $dayCategory = in_array($category, [
            self::DAY_STATUS_LESS_THAN_1,
            self::DAY_STATUS_LESS_THAN_7,
            self::DAY_STATUS_BETWEEN_7_AND_10,
        ], true) ? $category : null;
        $effectiveDayStatus = $dayStatus ?: $dayCategory;

        $filtered = $rows->filter(function (array $row) use (
            $category,
            $dayStatus,
            $dataStatus,
            $hasOvertime,
            $effectiveDayStatus,
            $search,
            $unitName,
            $registrationNumber,
            $wialonId,
            $filters
        ): bool {
            if ($dataStatus === 'available' && ! $row['data_available']) {
                return false;
            }

            if ($dataStatus === 'missing' && $row['data_available']) {
                return false;
            }

            if ($category === self::STATUS_NO_DATA && $row['data_available']) {
                return false;
            }

            if ($category === self::STATUS_OVERTIME && $row['has_overtime'] !== true) {
                return false;
            }

            if (
                ($category === self::DAY_STATUS_OVER_10 || $dayStatus === self::DAY_STATUS_OVER_10)
                && (! $row['data_available'] || $row['total_hours'] === null || (float) $row['total_hours'] <= 10)
            ) {
                return false;
            }

            if ($effectiveDayStatus && $row['daytime_status'] !== $effectiveDayStatus) {
                return false;
            }

            if ($hasOvertime === 'yes' && $row['has_overtime'] !== true) {
                return false;
            }

            if ($hasOvertime === 'no' && $row['has_overtime'] !== false) {
                return false;
            }

            if (! $this->matchesRange($row['daytime_hours'], $filters['day_hours_min'] ?? null, $filters['day_hours_max'] ?? null)) {
                return false;
            }

            if (! $this->matchesRange($row['overtime_hours'], $filters['overtime_hours_min'] ?? null, $filters['overtime_hours_max'] ?? null)) {
                return false;
            }

            if (! $this->matchesRange($row['total_hours'], $filters['total_hours_min'] ?? null, $filters['total_hours_max'] ?? null)) {
                return false;
            }

            if ($unitName !== '' && ! str_contains(mb_strtolower((string) $row['name']), $unitName)) {
                return false;
            }

            if ($registrationNumber !== '' && ! str_contains(mb_strtolower((string) $row['registration_number']), $registrationNumber)) {
                return false;
            }

            if ($wialonId !== '' && ! str_contains(mb_strtolower((string) $row['wialon_id']), $wialonId)) {
                return false;
            }

            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $row['name'],
                    $row['registration_number'],
                    $row['project'],
                    $row['vehicle_type'],
                    $row['wialon_id'],
                ]));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        });

        return $this->sortRows($filtered, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Equipment>
     */
    private function eligibleEquipment(array $filters): Collection
    {
        return Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->operationalDashboardProject()
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership_type'], fn ($query, string $ownership) => $query->where('ownership_type', $ownership))
            ->when($filters['project_id'], fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['project_ids'], fn ($query, array $projectIds) => $query->whereIn('project_id', $projectIds))
            ->when($filters['equipment_type_id'], fn ($query, int $typeId) => $query->where('equipment_type_id', $typeId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Equipment $item): bool => $this->isAllowedType($item->type?->name))
            ->filter(fn (Equipment $item): bool => empty($filters['vehicle_types']) || in_array($this->typeCode($item->type?->name), $filters['vehicle_types'], true))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function dailyRowsQuery(array $filters): Builder
    {
        $dates = collect(iterator_to_array(CarbonPeriod::create($filters['from'], $filters['to'])))
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->values();

        $equipmentQuery = Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->leftJoin('projects', 'projects.id', '=', 'equipments.project_id')
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->operationalDashboardProject()
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->whereIn(DB::raw("LOWER(REPLACE(REPLACE(equipment_types.name, '-', '_'), ' ', '_'))"), $this->sqlTypeAliases($filters))
            ->when($filters['ownership_type'], fn ($query, string $ownership) => $query->where('equipments.ownership_type', $ownership))
            ->when($filters['project_id'], fn ($query, int $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['project_ids'], fn ($query, array $projectIds) => $query->whereIn('equipments.project_id', $projectIds))
            ->when($filters['equipment_type_id'], fn ($query, int $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->select([
                'equipments.id as equipment_id',
                'equipments.wialon_unit_id',
                'equipments.name',
                'equipments.registration_number',
                'equipments.ownership_type',
                'equipments.project_id',
                'equipment_types.name as type_name',
                'projects.name as project_name',
            ]);

        $latestStats = EquipmentDailyStat::query()
            ->selectRaw('MAX(id) as id, equipment_id, stat_date')
            ->whereDate('stat_date', '>=', $filters['from'])
            ->whereDate('stat_date', '<=', $filters['to'])
            ->groupBy('equipment_id', 'stat_date');

        return DB::query()
            ->fromSub($equipmentQuery, 'equipment')
            ->crossJoinSub($this->dateSeriesQuery($dates), 'dates')
            ->leftJoinSub($latestStats, 'latest_stats', function ($join): void {
                $join->on('latest_stats.equipment_id', '=', 'equipment.equipment_id')
                    ->on('latest_stats.stat_date', '=', 'dates.stat_date');
            })
            ->leftJoin('equipment_daily_stats as stats', 'stats.id', '=', 'latest_stats.id')
            ->select([
                'dates.stat_date',
                'equipment.equipment_id',
                'equipment.wialon_unit_id',
                'equipment.name',
                'equipment.registration_number',
                'equipment.type_name',
                'equipment.ownership_type',
                'equipment.project_id',
                'equipment.project_name',
                'stats.id as stat_id',
                'stats.daytime_hours',
                'stats.overtime_hours',
                'stats.total_hours',
                'stats.day_status',
                'stats.has_overtime',
                'stats.data_available',
                'stats.calculation_source',
                'stats.source_group_id',
                'stats.source_intervals_json',
            ]);
    }

    /**
     * @param  Collection<int, string>  $dates
     */
    private function dateSeriesQuery(Collection $dates): Builder
    {
        $query = null;

        foreach ($dates as $date) {
            $dateQuery = DB::query()->selectRaw('? as stat_date', [$date]);
            $query = $query ? $query->unionAll($dateQuery) : $dateQuery;
        }

        return $query ?? DB::query()->selectRaw('? as stat_date', [now(config('app.timezone'))->toDateString()])->whereRaw('1 = 0');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function sqlTypeAliases(array $filters): array
    {
        $requestedTypes = $filters['vehicle_types'] === []
            ? config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', []))
            : array_map(fn (string $type): string => str_replace('-', '_', $type), $filters['vehicle_types']);

        $requestedTypes = collect($requestedTypes)
            ->map(fn (string $type): string => FleetVehicleType::normalize($type))
            ->unique()
            ->values()
            ->all();

        return collect(FleetVehicleType::aliases())
            ->filter(fn (string $target): bool => in_array($target, $requestedTypes, true))
            ->keys()
            ->merge($requestedTypes)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDailyRowFilters(Builder $query, array $filters): void
    {
        $category = $filters['work_category'] ?? null;
        $dayStatus = $filters['day_status'] ?? null;
        $effectiveDayStatus = $dayStatus ?: (in_array($category, $this->dayStatusKeys(), true) ? $category : null);
        $dataStatus = $filters['data_status'] ?? 'all';
        $hasOvertime = $filters['has_overtime'] ?? 'all';
        $availableSql = $this->dataAvailableSql();

        if ($dataStatus === 'available') {
            $query->whereRaw($availableSql);
        }

        if ($dataStatus === 'missing' || $category === self::STATUS_NO_DATA) {
            $query->whereRaw('NOT '.$availableSql);
        }

        if ($category === self::STATUS_OVERTIME) {
            $query->where('stats.overtime_hours', '>', 0);
        }

        if ($effectiveDayStatus) {
            $this->applyDayStatusFilter($query, $effectiveDayStatus);
        }

        if ($hasOvertime === 'yes') {
            $query->where('stats.overtime_hours', '>', 0);
        }

        if ($hasOvertime === 'no') {
            $query->whereNotNull('stats.overtime_hours')
                ->where('stats.overtime_hours', '<=', 0);
        }

        $this->applyRangeFilter($query, 'stats.daytime_hours', $filters['day_hours_min'] ?? null, $filters['day_hours_max'] ?? null);
        $this->applyRangeFilter($query, 'stats.overtime_hours', $filters['overtime_hours_min'] ?? null, $filters['overtime_hours_max'] ?? null);
        $this->applyRangeFilter($query, $this->totalHoursSql(), $filters['total_hours_min'] ?? null, $filters['total_hours_max'] ?? null, raw: true);
        $this->applyTextFilter($query, 'equipment.name', $filters['unit_name'] ?? '');
        $this->applyTextFilter($query, 'equipment.registration_number', $filters['registration_number'] ?? '');
        $this->applyTextFilter($query, 'equipment.wialon_unit_id', $filters['wialon_id'] ?? '');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $search = mb_strtolower($search);
            $query->where(function (Builder $query) use ($search): void {
                foreach (['equipment.name', 'equipment.registration_number', 'equipment.project_name', 'equipment.type_name', 'equipment.wialon_unit_id'] as $column) {
                    $query->orWhereRaw('LOWER(COALESCE('.$column.", '')) LIKE ?", ['%'.$search.'%']);
                }
            });
        }
    }

    private function applyDayStatusFilter(Builder $query, string $status): void
    {
        $availableSql = $this->dataAvailableSql();
        match ($status) {
            self::DAY_STATUS_LESS_THAN_1 => $query->whereRaw($availableSql)
                ->where('stats.daytime_hours', '<', 1),
            self::DAY_STATUS_LESS_THAN_7 => $query->whereRaw($availableSql)
                ->where('stats.daytime_hours', '>=', 1)
                ->where('stats.daytime_hours', '<', 7),
            self::DAY_STATUS_BETWEEN_7_AND_10 => $query->whereRaw($availableSql)
                ->where('stats.daytime_hours', '>=', 7)
                ->where('stats.daytime_hours', '<=', 10),
            self::DAY_STATUS_OVER_10 => $query->whereRaw($availableSql)
                ->whereRaw($this->totalHoursSql().' > 10'),
            default => null,
        };
    }

    private function applyRangeFilter(Builder $query, string $column, mixed $min, mixed $max, bool $raw = false): void
    {
        if ($min === null && $max === null) {
            return;
        }

        $raw
            ? $query->whereRaw($column.' IS NOT NULL')
            : $query->whereNotNull($column);

        if ($min !== null) {
            $raw
                ? $query->whereRaw($column.' >= ?', [(float) $min])
                : $query->where($column, '>=', (float) $min);
        }

        if ($max !== null) {
            $raw
                ? $query->whereRaw($column.' <= ?', [(float) $max])
                : $query->where($column, '<=', (float) $max);
        }
    }

    private function applyTextFilter(Builder $query, string $column, mixed $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        $query->whereRaw('LOWER(COALESCE('.$column.", '')) LIKE ?", ['%'.mb_strtolower($value).'%']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDailyRowSort(Builder $query, array $filters): void
    {
        $sort = $this->sort($filters['sort'] ?? null);
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'date' => $query->orderBy('dates.stat_date', $direction),
            'registration_number' => $query->orderByRaw('LOWER(COALESCE(equipment.registration_number, \'\')) '.$direction),
            'vehicle_type' => $query->orderByRaw('LOWER(COALESCE(equipment.type_name, \'\')) '.$direction),
            'project' => $query->orderByRaw('LOWER(COALESCE(equipment.project_name, \'\')) '.$direction),
            'ownership' => $query->orderBy('equipment.ownership_type', $direction),
            'daytime_hours' => $query->orderByRaw('COALESCE(stats.daytime_hours, -1) '.$direction),
            'overtime_hours' => $query->orderByRaw('COALESCE(stats.overtime_hours, -1) '.$direction),
            'total_hours' => $query->orderByRaw('COALESCE('.$this->totalHoursSql().', -1) '.$direction),
            'wialon_id' => $query->orderBy('equipment.wialon_unit_id', $direction),
            default => $query->orderByRaw('LOWER(COALESCE(equipment.name, \'\')) '.$direction),
        };

        $query
            ->orderBy('dates.stat_date')
            ->orderByRaw('LOWER(COALESCE(equipment.name, \'\'))')
            ->orderBy('equipment.wialon_unit_id');
    }

    private function dataAvailableSql(): string
    {
        return '(stats.id IS NOT NULL AND stats.daytime_hours IS NOT NULL)';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function summaryAggregates(array $filters, bool $byProject): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $query = $this->dailyRowsQuery($filters);
        $availableSql = $this->dataAvailableSql();
        $selects = [
            'equipment.ownership_type',
            DB::raw('SUM(CASE WHEN '.$availableSql.' AND stats.daytime_hours < 1 THEN 1 ELSE 0 END) as '.self::DAY_STATUS_LESS_THAN_1),
            DB::raw('SUM(CASE WHEN '.$availableSql.' AND stats.daytime_hours >= 1 AND stats.daytime_hours < 7 THEN 1 ELSE 0 END) as '.self::DAY_STATUS_LESS_THAN_7),
            DB::raw('SUM(CASE WHEN '.$availableSql.' AND stats.daytime_hours >= 7 AND stats.daytime_hours <= 10 THEN 1 ELSE 0 END) as '.self::DAY_STATUS_BETWEEN_7_AND_10),
            DB::raw('SUM(CASE WHEN '.$availableSql.' AND '.$this->totalHoursSql().' > 10 THEN 1 ELSE 0 END) as '.self::DAY_STATUS_OVER_10),
            DB::raw('COUNT(*) as total_rows'),
            DB::raw('SUM(CASE WHEN NOT '.$availableSql.' THEN 1 ELSE 0 END) as '.self::STATUS_NO_DATA),
            DB::raw('SUM(CASE WHEN NOT '.$availableSql.' THEN 1 ELSE 0 END) as missing_data'),
            DB::raw('SUM(CASE WHEN stats.overtime_hours IS NOT NULL THEN 1 ELSE 0 END) as overtime_denominator'),
            DB::raw('SUM(CASE WHEN stats.overtime_hours IS NULL THEN 1 ELSE 0 END) as overtime_unknown'),
            DB::raw('SUM(CASE WHEN stats.overtime_hours > 0 THEN 1 ELSE 0 END) as '.self::STATUS_OVERTIME),
        ];

        if ($byProject) {
            $selects[] = 'equipment.project_id';
            $selects[] = DB::raw("COALESCE(equipment.project_name, '-') as project");
            $query->groupBy('equipment.ownership_type', 'equipment.project_id', 'equipment.project_name');
        } else {
            $query->groupBy('equipment.ownership_type');
        }

        return $query
            ->select($selects)
            ->get()
            ->map(function (object $row): array {
                return [
                    'ownership_type' => $row->ownership_type,
                    'project_id' => $row->project_id ?? null,
                    'project' => $row->project ?? '-',
                    self::DAY_STATUS_LESS_THAN_1 => (int) $row->{self::DAY_STATUS_LESS_THAN_1},
                    self::DAY_STATUS_LESS_THAN_7 => (int) $row->{self::DAY_STATUS_LESS_THAN_7},
                    self::DAY_STATUS_BETWEEN_7_AND_10 => (int) $row->{self::DAY_STATUS_BETWEEN_7_AND_10},
                    self::DAY_STATUS_OVER_10 => (int) $row->{self::DAY_STATUS_OVER_10},
                    self::STATUS_OVERTIME => (int) $row->{self::STATUS_OVERTIME},
                    self::STATUS_NO_DATA => (int) $row->{self::STATUS_NO_DATA},
                    'total_rows' => (int) $row->total_rows,
                    'missing_data' => (int) $row->missing_data,
                    'overtime_denominator' => (int) $row->overtime_denominator,
                    'overtime_unknown' => (int) $row->overtime_unknown,
                ];
            });
    }

    /**
     * @return array<int, string>
     */
    private function aggregateKeys(): array
    {
        return [
            self::DAY_STATUS_LESS_THAN_1,
            self::DAY_STATUS_LESS_THAN_7,
            self::DAY_STATUS_BETWEEN_7_AND_10,
            self::DAY_STATUS_OVER_10,
            self::STATUS_OVERTIME,
            self::STATUS_NO_DATA,
            'total_rows',
            'missing_data',
            'overtime_denominator',
            'overtime_unknown',
        ];
    }

    private function totalHoursSql(): string
    {
        return '(CASE WHEN stats.daytime_hours IS NOT NULL AND stats.overtime_hours IS NOT NULL THEN stats.daytime_hours + stats.overtime_hours ELSE stats.total_hours END)';
    }

    private function dailyRowFromRecord(object $row): array
    {
        $daytimeHours = $row->daytime_hours === null ? null : max(0.0, (float) $row->daytime_hours);
        $overtimeHours = $row->overtime_hours === null ? null : max(0.0, (float) $row->overtime_hours);
        $totalHours = $daytimeHours !== null && $overtimeHours !== null
            ? $daytimeHours + $overtimeHours
            : ($row->total_hours === null ? null : max(0.0, (float) $row->total_hours));
        $dataAvailable = $row->stat_id !== null
            && $daytimeHours !== null;
        $daytimeStatus = $dataAvailable ? $this->statusForHours($daytimeHours) : self::STATUS_NO_DATA;
        $hasOvertime = $overtimeHours === null ? null : $overtimeHours > 0;
        $sourceIntervals = is_string($row->source_intervals_json ?? null)
            ? json_decode($row->source_intervals_json, true) ?: []
            : [];

        return [
            'date' => Carbon::parse($row->stat_date)->toDateString(),
            'equipment_id' => (int) $row->equipment_id,
            'wialon_id' => $row->wialon_unit_id,
            'name' => $row->name,
            'registration_number' => $row->registration_number ?: '-',
            'vehicle_type' => $this->displayType($row->type_name),
            'ownership_type' => $row->ownership_type,
            'ownership' => $this->ownershipLabel($row->ownership_type),
            'project_id' => $row->project_id,
            'project' => $row->project_name ?: '-',
            'daytime_hours' => $daytimeHours === null ? null : round($daytimeHours, 2),
            'overtime_hours' => $overtimeHours === null ? null : round($overtimeHours, 2),
            'total_hours' => $totalHours === null ? null : round($totalHours, 2),
            'daytime_status' => $daytimeStatus,
            'daytime_status_label' => $this->statusLabel($daytimeStatus),
            'has_overtime' => $hasOvertime,
            'overtime_calculated' => $overtimeHours !== null,
            'overtime_label' => $hasOvertime === null ? __('app.no_data') : ($hasOvertime ? 'BЙ™li' : 'Xeyr'),
            'data_available' => $dataAvailable,
            'data_status' => $dataAvailable ? 'Məlumat var' : __('app.no_data'),
            'source' => $row->calculation_source,
            'source_group_id' => $row->source_group_id,
            'source_intervals' => $sourceIntervals,
        ];
    }

    private function dailyRow(Equipment $equipment, string $date, ?EquipmentDailyStat $stat): array
    {
        $daytimeHours = $stat ? $this->daytimeHours($stat) : null;
        $overtimeHours = $stat ? $this->overtimeHours($stat) : null;
        $totalHours = $stat ? $this->totalHours($stat, $daytimeHours, $overtimeHours) : null;
        $dataAvailable = $stat !== null && $daytimeHours !== null;
        $daytimeStatus = $dataAvailable ? $this->statusForHours($daytimeHours) : self::STATUS_NO_DATA;
        $hasOvertime = $overtimeHours === null ? null : $overtimeHours > 0;

        return [
            'date' => $date,
            'equipment_id' => $equipment->id,
            'wialon_id' => $equipment->wialon_unit_id,
            'name' => $equipment->name,
            'registration_number' => $equipment->registration_number ?: '-',
            'vehicle_type' => $this->displayType($equipment->type?->name),
            'ownership_type' => $equipment->ownership_type,
            'ownership' => $this->ownershipLabel($equipment->ownership_type),
            'project_id' => $equipment->project_id,
            'project' => $equipment->project?->name ?: '-',
            'daytime_hours' => $daytimeHours === null ? null : round($daytimeHours, 2),
            'overtime_hours' => $overtimeHours === null ? null : round($overtimeHours, 2),
            'total_hours' => $totalHours === null ? null : round($totalHours, 2),
            'daytime_status' => $daytimeStatus,
            'daytime_status_label' => $this->statusLabel($daytimeStatus),
            'has_overtime' => $hasOvertime,
            'overtime_calculated' => $overtimeHours !== null,
            'overtime_label' => $hasOvertime === null ? __('app.no_data') : ($hasOvertime ? 'Bəli' : 'Xeyr'),
            'data_available' => $dataAvailable,
            'data_status' => $dataAvailable ? 'Məlumat var' : __('app.no_data'),
            'source' => $stat?->calculation_source,
            'source_group_id' => $stat?->source_group_id,
            'source_intervals' => $stat?->source_intervals_json ?? [],
        ];
    }

    private function daytimeHours(EquipmentDailyStat $stat): ?float
    {
        return $stat->daytime_hours === null ? null : max(0.0, (float) $stat->daytime_hours);
    }

    private function overtimeHours(EquipmentDailyStat $stat): ?float
    {
        return $stat->overtime_hours === null ? null : max(0.0, (float) $stat->overtime_hours);
    }

    private function totalHours(EquipmentDailyStat $stat, ?float $daytimeHours, ?float $overtimeHours): ?float
    {
        if ($daytimeHours !== null && $overtimeHours !== null) {
            return $daytimeHours + $overtimeHours;
        }

        return $stat->total_hours === null ? null : max(0.0, (float) $stat->total_hours);
    }

    private function statusForHours(float $daytimeHours): string
    {
        if ($daytimeHours < 1) {
            return self::DAY_STATUS_LESS_THAN_1;
        }

        if ($daytimeHours < 7) {
            return self::DAY_STATUS_LESS_THAN_7;
        }

        if ($daytimeHours <= 10) {
            return self::DAY_STATUS_BETWEEN_7_AND_10;
        }

        return self::DAY_STATUS_OVER_10;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::DAY_STATUS_LESS_THAN_1 => __('app.worked_less_than_1_hour'),
            self::DAY_STATUS_LESS_THAN_7 => __('app.worked_less_than_7_hours'),
            self::DAY_STATUS_BETWEEN_7_AND_10 => __('app.worked_7_to_10_hours'),
            self::DAY_STATUS_OVER_10, self::LEGACY_DAY_STATUS_OVER_10 => __('app.worked_over_10_hours'),
            self::STATUS_OVERTIME => __('app.worked_overtime_hours'),
            self::STATUS_NO_DATA => __('app.no_data'),
            default => $status,
        };
    }

    private function isAllowedType(?string $name): bool
    {
        $allowed = config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', []));

        return in_array($this->typeCode($name), $allowed, true);
    }

    private function displayType(?string $name): string
    {
        return FleetVehicleType::display($name);
    }

    private function typeCode(?string $name): string
    {
        return FleetVehicleType::slug($name);
    }

    private function emptyProjectRow(int $projectId, string $projectName): array
    {
        return [
            'id' => $projectId,
            'name' => $projectName,
            ...$this->emptySummary(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            self::DAY_STATUS_LESS_THAN_1 => 0,
            self::DAY_STATUS_LESS_THAN_7 => 0,
            self::DAY_STATUS_BETWEEN_7_AND_10 => 0,
            self::DAY_STATUS_OVER_10 => 0,
            self::STATUS_OVERTIME => 0,
            self::STATUS_NO_DATA => 0,
            'total' => 0,
            'missing_data' => 0,
            'overtime_denominator' => 0,
            'overtime_unknown' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function daytimeTotal(array $summary): int
    {
        return (int) (
            ($summary[self::DAY_STATUS_LESS_THAN_1] ?? 0)
            + ($summary[self::DAY_STATUS_LESS_THAN_7] ?? 0)
            + ($summary[self::DAY_STATUS_BETWEEN_7_AND_10] ?? 0)
            + ($summary[self::DAY_STATUS_OVER_10] ?? 0)
        );
    }

    private function exportRow(array $row, int $number): array
    {
        return [
            $number,
            $row['date'],
            $row['name'],
            $row['registration_number'],
            $row['vehicle_type'],
            $row['ownership'],
            $row['project'],
            $row['daytime_hours'] === null ? '-' : $row['daytime_hours'],
            $row['overtime_hours'] === null ? '-' : $row['overtime_hours'],
            $row['total_hours'] === null ? '-' : $row['total_hours'],
            $row['daytime_status_label'],
            $row['overtime_label'],
            $row['data_status'],
            $row['wialon_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $range = $this->dateRangePolicy->normalize([
            ...$filters,
            '_default_from' => now(config('app.timezone'))->toDateString(),
            '_default_to' => $filters['from'] ?? $filters['date_from'] ?? now(config('app.timezone'))->toDateString(),
        ], 'modal');

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'project_ids' => $this->integerArray($filters['project_ids'] ?? []),
            'equipment_type_id' => filled($filters['equipment_type_id'] ?? null) ? (int) $filters['equipment_type_id'] : null,
            'vehicle_types' => $this->vehicleTypes($filters['vehicle_types'] ?? []),
            'ownership_type' => $filters['ownership_type'] ?? null,
            'work_category' => $this->workCategory($filters['work_category'] ?? null),
            'day_status' => $this->dayStatus($filters['day_status'] ?? null),
            'data_status' => $this->dataStatus($filters['data_status'] ?? null),
            'has_overtime' => $this->hasOvertime($filters['has_overtime'] ?? null),
            'day_hours_min' => $this->nullableFloat($filters['day_hours_min'] ?? null),
            'day_hours_max' => $this->nullableFloat($filters['day_hours_max'] ?? null),
            'overtime_hours_min' => $this->nullableFloat($filters['overtime_hours_min'] ?? null),
            'overtime_hours_max' => $this->nullableFloat($filters['overtime_hours_max'] ?? null),
            'total_hours_min' => $this->nullableFloat($filters['total_hours_min'] ?? null),
            'total_hours_max' => $this->nullableFloat($filters['total_hours_max'] ?? null),
            'unit_name' => trim((string) ($filters['unit_name'] ?? '')),
            'registration_number' => trim((string) ($filters['registration_number'] ?? '')),
            'wialon_id' => trim((string) ($filters['wialon_id'] ?? '')),
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort' => $this->sort($filters['sort'] ?? null),
            'direction' => ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ];
    }

    private function ownershipLabel(?string $ownershipType): string
    {
        return $ownershipType === Equipment::OWNERSHIP_ICARE ? 'İCARƏ' : 'NWC';
    }

    private function dataStatus(?string $status): string
    {
        return in_array($status, ['available', 'missing'], true) ? $status : 'all';
    }

    private function hasOvertime(?string $status): string
    {
        return in_array($status, ['yes', 'no'], true) ? $status : 'all';
    }

    private function workCategory(?string $category): ?string
    {
        return match ($category) {
            'less_than_1', self::DAY_STATUS_LESS_THAN_1 => self::DAY_STATUS_LESS_THAN_1,
            'from_1_to_7', self::DAY_STATUS_LESS_THAN_7 => self::DAY_STATUS_LESS_THAN_7,
            'from_7_to_10', self::DAY_STATUS_BETWEEN_7_AND_10 => self::DAY_STATUS_BETWEEN_7_AND_10,
            self::DAY_STATUS_OVER_10, self::LEGACY_DAY_STATUS_OVER_10 => self::DAY_STATUS_OVER_10,
            self::STATUS_OVERTIME => self::STATUS_OVERTIME,
            self::STATUS_NO_DATA => self::STATUS_NO_DATA,
            default => null,
        };
    }

    private function dayStatus(?string $status): ?string
    {
        return match ($status) {
            'less_than_1', self::DAY_STATUS_LESS_THAN_1 => self::DAY_STATUS_LESS_THAN_1,
            'from_1_to_7', self::DAY_STATUS_LESS_THAN_7 => self::DAY_STATUS_LESS_THAN_7,
            'from_7_to_10', self::DAY_STATUS_BETWEEN_7_AND_10 => self::DAY_STATUS_BETWEEN_7_AND_10,
            self::DAY_STATUS_OVER_10, self::LEGACY_DAY_STATUS_OVER_10 => self::DAY_STATUS_OVER_10,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function dayStatusKeys(): array
    {
        return [
            self::DAY_STATUS_LESS_THAN_1,
            self::DAY_STATUS_LESS_THAN_7,
            self::DAY_STATUS_BETWEEN_7_AND_10,
            self::DAY_STATUS_OVER_10,
        ];
    }

    private function matchesRange(mixed $value, mixed $min, mixed $max): bool
    {
        if ($min === null && $max === null) {
            return true;
        }

        if ($value === null || $value === '') {
            return false;
        }

        $value = (float) $value;

        if ($min !== null && $value < (float) $min) {
            return false;
        }

        if ($max !== null && $value > (float) $max) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, array $filters): Collection
    {
        $sort = $this->sort($filters['sort'] ?? null);
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $rows
            ->sortBy(fn (array $row): array => [
                $this->sortValue($row, $sort),
                mb_strtolower((string) ($row['name'] ?? '')),
                (string) ($row['wialon_id'] ?? ''),
            ], SORT_REGULAR, $direction === 'desc')
            ->values();
    }

    private function sortValue(array $row, string $sort): mixed
    {
        return match ($sort) {
            'date' => $row['date'] ?? '',
            'registration_number' => mb_strtolower((string) ($row['registration_number'] ?? '')),
            'vehicle_type' => mb_strtolower((string) ($row['vehicle_type'] ?? '')),
            'project' => mb_strtolower((string) ($row['project'] ?? '')),
            'ownership' => mb_strtolower((string) ($row['ownership'] ?? '')),
            'daytime_hours' => $row['daytime_hours'] === null ? -1 : (float) $row['daytime_hours'],
            'overtime_hours' => $row['overtime_hours'] === null ? -1 : (float) $row['overtime_hours'],
            'total_hours' => $row['total_hours'] === null ? -1 : (float) $row['total_hours'],
            'wialon_id' => (string) ($row['wialon_id'] ?? ''),
            default => mb_strtolower((string) ($row['name'] ?? '')),
        };
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
            'daytime_hours',
            'overtime_hours',
            'total_hours',
            'wialon_id',
        ], true) ? $sort : 'date';
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
        $allowed = config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', []));

        return collect(is_array($value) ? $value : [$value])
            ->map(fn (mixed $item): string => FleetVehicleType::slug((string) $item))
            ->filter(fn (string $slug): bool => in_array($slug, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (float) $value);
    }
}
