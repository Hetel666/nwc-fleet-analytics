<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Support\DashboardDateRangePolicy;
use App\Support\FleetVehicleType;
use App\Support\MonthlyEfficiencyStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MonthlyEfficiencyDashboardService
{
    private const MODULE_CODE = 'monthly_efficiency';

    private const NORMATIVE_HOURS = 200.0;

    private const ALLOWED_TYPES = FleetVehicleType::EFFICIENCY_TYPES;

    private const EXCLUDED_PROJECT_NAMES = [
        'Layihəsiz',
        'Təmir',
        'Layihəsiz',
        '-Layihəsiz-',
        'Layihesiz',
        '-Layihesiz-',
        'Təmir',
        'Temir',
    ];

    public function __construct(
        private DashboardDateRangePolicy $dateRangePolicy,
        private MonthlyEfficiencyProjectResolver $projectResolver,
    ) {}

    public function isReady(): bool
    {
        return $this->usesDailyStatsSource()
            ? Schema::hasTable('equipment_daily_stats')
            : Schema::hasTable('efficiency_daily_facts');
    }

    /** @return array<string, mixed> */
    public function summaryForOwnership(array $filters, string $ownership): array
    {
        if (! $this->isReady()) {
            return $this->emptySummary($filters);
        }

        $filters = $this->normalizeFilters([...$filters, 'ownership_type' => $ownership]);
        $rows = $this->monthlyUnitRows($filters, false)->groupBy('monthly_status');
        $summary = collect(MonthlyEfficiencyStatus::labels())
            ->mapWithKeys(fn (string $label, string $status): array => [
                $status => (int) $rows->get($status, collect())->count(),
            ])
            ->all();
        $totalUnits = array_sum($summary);
        $normalUnits = (int) ($summary[MonthlyEfficiencyStatus::NORMAL] ?? 0);
        $totalCurrentHours = (float) $rows->flatten(1)->sum(fn (object $row): float => (float) $row->current_hours);
        $totalNormativeHours = $totalUnits * self::NORMATIVE_HOURS;
        $summary['total'] = $totalUnits;
        $summary['total_current_hours'] = round($totalCurrentHours, 2);
        $summary['total_normative_hours'] = round($totalNormativeHours, 2);
        $summary['efficiency_percent'] = $totalUnits > 0
            ? round($normalUnits / $totalUnits * 100, 2)
            : 0.0;
        $summary['month'] = $filters['month'];
        $summary['period'] = ['from' => $filters['from'], 'to' => $filters['to']];
        $summary['completeness'] = $this->completeness($filters);

        return $summary;
    }

    /** @return array<int, array{status: string, label: string, count: int}> */
    public function summary(array $filters): array
    {
        if (! $this->isReady()) {
            return collect(MonthlyEfficiencyStatus::labels())
                ->map(fn (string $label, string $status): array => ['status' => $status, 'label' => $label, 'count' => 0])
                ->values()
                ->all();
        }

        $filters = $this->normalizeFilters($filters);
        $counts = $this->monthlyUnitRows($filters, $this->usesProjectScope($filters))
            ->groupBy('monthly_status')
            ->map(fn (Collection $rows): int => $rows->count());

        return collect(MonthlyEfficiencyStatus::labels())
            ->map(fn (string $label, string $status): array => [
                'status' => $status,
                'label' => $label,
                'count' => (int) ($counts[$status] ?? 0),
            ])
            ->values()
            ->all();
    }

    public function paginateProjects(array $filters): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->monthlyUnitRows($filters, true)
            ->groupBy(fn (object $row): string => $row->project_id.'|'.$row->project.'|'.$row->ownership.'|'.$row->monthly_status)
            ->map(function (Collection $rows): object {
                $first = $rows->first();
                $count = $rows->count();
                $current = round((float) $rows->sum(fn (object $row): float => (float) $row->current_hours), 2);
                $normative = $count * self::NORMATIVE_HOURS;

                return (object) [
                    'project_id' => (int) $first->project_id,
                    'project' => $first->project,
                    'ownership' => $first->ownership,
                    'monthly_status' => $first->monthly_status,
                    'unique_units_count' => $count,
                    'total_current_hours' => $current,
                    'total_normative_hours' => $normative,
                    'efficiency_percent' => $normative > 0 ? round($current * 100 / $normative, 2) : 0.0,
                ];
            })
            ->sortBy([
                ['unique_units_count', 'desc'],
                ['project', 'asc'],
            ])
            ->values()
            ->map(fn (object $row): array => [
                'project_id' => (int) $row->project_id,
                'project' => $row->project,
                'ownership' => $this->ownershipLabel($row->ownership),
                'status' => MonthlyEfficiencyStatus::labels()[$row->monthly_status] ?? $row->monthly_status,
                'count' => (int) $row->unique_units_count,
                'unique_units_count' => (int) $row->unique_units_count,
                'total_current_hours' => number_format((float) $row->total_current_hours, 2, '.', ''),
                'total_normative_hours' => number_format((float) $row->total_normative_hours, 2, '.', ''),
                'efficiency_percent' => number_format((float) $row->efficiency_percent, 2, '.', '').'%',
            ]);

        return $this->paginateCollection($rows, $filters);
    }

    public function paginateUnits(array $filters): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $sorts = [
            'name' => 'unit_name',
            'registration_number' => 'registration_number',
            'vehicle_type' => 'vehicle_type',
            'project' => 'project',
            'ownership' => 'ownership',
            'current_hours' => 'current_hours',
            'normative_hours' => 'normative_hours',
            'efficiency_percent' => 'efficiency_percent',
            'status' => 'monthly_status',
        ];
        $sort = $sorts[$filters['sort']] ?? 'current_hours';
        $direction = $filters['direction'] ?: ($filters['status'] === MonthlyEfficiencyStatus::NORMAL ? 'desc' : 'asc');

        $rows = $this->monthlyUnitRows($filters, true)
            ->sortBy([
                [$sort, $direction],
                ['unit_name', 'asc'],
            ])
            ->values()
            ->map(fn (object $row): array => $this->unitRow($row));

        return $this->paginateCollection($rows, $filters);
    }

    /** @return array<string, mixed> */
    public function export(array $filters): array
    {
        $filters = $this->normalizeFilters($filters, 'export');
        $unitRows = $this->monthlyUnitRows($filters, true)
            ->sortBy([
                ['project', 'asc'],
                ['unit_name', 'asc'],
            ])
            ->values();
        $summaryRows = $unitRows
            ->groupBy('monthly_status')
            ->map(function ($rows, string $status) use ($filters): array {
                $count = $rows->count();
                $current = round((float) $rows->sum(fn (object $row): float => (float) $row->current_hours), 2);
                $normative = $count * self::NORMATIVE_HOURS;

                return [
                    $this->ownershipLabel((string) $filters['ownership_type']),
                    MonthlyEfficiencyStatus::labels()[$status] ?? $status,
                    $count,
                    number_format($current, 2, '.', ''),
                    number_format($normative, 2, '.', ''),
                    number_format($normative > 0 ? $current / $normative * 100 : 0, 2, '.', '').'%',
                ];
            })
            ->values()
            ->all();
        $projectRows = $unitRows
            ->groupBy(fn (object $row): string => $row->project_id.'|'.$row->project.'|'.$row->ownership)
            ->map(function ($rows): array {
                $first = $rows->first();

                return [
                    $first->project,
                    $this->ownershipLabel($first->ownership),
                    $rows->where('monthly_status', MonthlyEfficiencyStatus::CRITICAL_LOW)->count(),
                    $rows->where('monthly_status', MonthlyEfficiencyStatus::LOW)->count(),
                    $rows->where('monthly_status', MonthlyEfficiencyStatus::NORMAL)->count(),
                    $rows->count(),
                ];
            })
            ->values()
            ->all();
        $detailRows = $unitRows
            ->map(fn (object $row, int $index): array => [
                $index + 1,
                $row->registration_number ?: $row->unit_name,
                $row->vehicle_type,
                $row->project,
                $row->period_from.' - '.$row->period_to,
                (int) $row->synced_days_count,
                number_format((float) $row->current_hours, 2, '.', ''),
                number_format((float) $row->normative_hours, 2, '.', ''),
                number_format((float) $row->efficiency_percent, 2, '.', '').'%',
                $this->projectSourceLabel((string) $row->project_source),
                $this->ownershipLabel($row->ownership),
                MonthlyEfficiencyStatus::labels()[$row->monthly_status] ?? $row->monthly_status,
            ])
            ->all();
        $filterRows = [
            ['Dövr', $filters['from'].' - '.$filters['to']],
            ['Ay', $filters['month']],
            ['Ownership', $filters['ownership_type'] ? $this->ownershipLabel($filters['ownership_type']) : 'Hamısı'],
            ['Hesablama vahidi', 'Unikal texnika'],
            ['Normativ MS', number_format(self::NORMATIVE_HOURS, 0, '.', '')],
            ['Mənbə', $this->sourceReportName()],
        ];
        $sections = [
            ['title' => 'Xülasə', 'columns' => ['Ownership', 'Status', 'Unique unit count', 'Total Cari MS', 'Total Normativ MS', 'Effektivlik %'], 'rows' => $summaryRows],
            ['title' => 'Layihələr', 'columns' => ['Layihə', 'Ownership', 'Kritik aşağı', 'Aşağı', 'Normal', 'Total units'], 'rows' => $projectRows],
            ['title' => 'Texnika üzrə', 'columns' => ['№', 'D.Q.N.', 'Texnika tipi', 'Layihə', 'Layihədə dövr', 'Layihədə gün', 'Layihə üzrə MS', 'Normativ MS', 'Effektivlik %', 'Layihə mənbəyi', 'Mənsubiyyət', 'Status'], 'rows' => $detailRows],
        ];

        return [
            'filename' => 'ayliq-effektivlik-'.$filters['ownership_type'].'-'.$filters['month'].'.xlsx',
            'title' => 'Aylıq effektivlik',
            'filters' => $filterRows,
            'sections' => $sections,
            'sheets' => [
                ['name' => 'Xülasə', 'title' => 'Aylıq effektivlik', 'filters' => $filterRows, 'sections' => [$sections[0]]],
                ['name' => 'Layihələr', 'title' => 'Aylıq effektivlik', 'filters' => $filterRows, 'sections' => [$sections[1]]],
                ['name' => 'Texnika üzrə', 'title' => 'Aylıq effektivlik', 'filters' => $filterRows, 'sections' => [$sections[2]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function normalizeFilters(array $filters, string $context = 'dashboard'): array
    {
        $period = $this->monthPeriod($filters, $context);
        $ownership = $filters['ownership_type'] ?? $filters['ownership'] ?? null;
        $ownership = match (mb_strtolower((string) $ownership)) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => null,
        };
        $status = $this->canonicalStatus($filters['status'] ?? null);
        $hasVisibleStatusRestriction = array_key_exists('visible_statuses', $filters)
            && $filters['visible_statuses'] !== null;
        $visibleStatuses = collect($filters['visible_statuses'] ?? [])
            ->map(fn ($visibleStatus): ?string => $this->canonicalStatus((string) $visibleStatus))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $vehicleTypes = collect($filters['vehicle_types'] ?? [$filters['vehicle_type'] ?? null])
            ->filter()
            ->map(fn ($type): string => FleetVehicleType::label((string) $type))
            ->unique()
            ->values()
            ->all();

        if (filled($filters['equipment_type_id'] ?? null)) {
            $type = EquipmentType::query()->whereKey($filters['equipment_type_id'])->value('name');
            $vehicleTypes = $type ? [FleetVehicleType::label($type)] : [];
        }

        if ($vehicleTypes === []) {
            $vehicleTypes = $this->allowedVehicleTypeLabels();
        } else {
            $allowed = $this->allowedVehicleTypeLabels();
            $vehicleTypes = collect($vehicleTypes)->intersect($allowed)->values()->all();
        }

        return [
            'month' => $period['month'],
            'from' => $period['from'],
            'to' => $period['to'],
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'project_ids' => collect($filters['project_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->all(),
            'ownership_type' => $ownership,
            'vehicle_types' => $vehicleTypes,
            'status' => $status,
            'visible_statuses' => $hasVisibleStatusRestriction ? $visibleStatuses : null,
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort' => (string) ($filters['sort'] ?? ''),
            'direction' => ($filters['direction'] ?? '') === 'desc' ? 'desc' : (($filters['direction'] ?? '') === 'asc' ? 'asc' : ''),
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($filters['per_page'] ?? 20))),
        ];
    }

    private function monthlyUnitRows(array $filters, bool $byProject): Collection
    {
        $filters = $this->normalizeFilters($filters);

        $rows = $this->dailyFactRows($filters)
            ->groupBy(fn (object $row): string => implode('|', array_filter([
                $byProject ? (string) $row->project_id : null,
                (string) $row->ownership,
                (string) $row->wialon_unit_id,
            ], fn (?string $part): bool => $part !== null)))
            ->map(function (Collection $rows) use ($byProject): object {
                $first = $rows->sortBy('business_date')->first();
                $currentHours = round((float) $rows->sum(fn (object $row): float => (float) $row->engine_hours_decimal), 2);
                $efficiencyPercent = round($currentHours * 100 / self::NORMATIVE_HOURS, 2);
                $projects = $rows->pluck('project')->filter()->unique()->values()->all();
                $projectSources = $rows->pluck('project_source')->filter()->unique()->values()->all();

                return (object) [
                    'project_id' => (int) $first->project_id,
                    'project' => $byProject ? $first->project : implode(', ', $projects),
                    'project_source' => implode(', ', $projectSources),
                    'ownership' => $first->ownership,
                    'wialon_unit_id' => $first->wialon_unit_id,
                    'unit_name' => $first->unit_name,
                    'registration_number' => $first->registration_number,
                    'vehicle_type' => $first->vehicle_type,
                    'period_from' => $rows->min('business_date'),
                    'period_to' => $rows->max('business_date'),
                    'synced_days_count' => $rows->pluck('business_date')->unique()->count(),
                    'current_hours' => $currentHours,
                    'normative_hours' => self::NORMATIVE_HOURS,
                    'efficiency_percent' => $efficiencyPercent,
                    'monthly_status' => MonthlyEfficiencyStatus::classify($currentHours),
                ];
            })
            ->values();

        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);
            $rows = $rows->filter(fn (object $row): bool => str_contains(mb_strtolower((string) $row->unit_name), $needle)
                || str_contains(mb_strtolower((string) $row->registration_number), $needle));
        }

        if ($filters['status'] !== null) {
            $rows = $rows->filter(fn (object $row): bool => $row->monthly_status === $filters['status']);
        } elseif (is_array($filters['visible_statuses'])) {
            $rows = $filters['visible_statuses'] === []
                ? collect()
                : $rows->filter(fn (object $row): bool => in_array($row->monthly_status, $filters['visible_statuses'], true));
        }

        return $rows->values();
    }

    private function dailyFactRows(array $filters): Collection
    {
        $filters = $this->normalizeFilters($filters);

        $rows = Cache::remember($this->dailyFactRowsCacheKey($filters), now()->addMinutes(5), function () use ($filters): Collection {
            return $this->resolvedDailyFactRows($filters);
        });

        return $rows
            ->when($filters['project_id'], fn (Collection $rows): Collection => $rows->where('project_id', $filters['project_id']))
            ->when($filters['project_ids'], fn (Collection $rows): Collection => $rows->whereIn('project_id', $filters['project_ids']))
            ->values();
    }

    private function resolvedDailyFactRows(array $filters): Collection
    {
        if ($this->usesDailyStatsSource()) {
            return $this->resolvedDailyStatsRows($filters);
        }

        $excludedProjects = collect(self::EXCLUDED_PROJECT_NAMES)
            ->map(fn (string $name): string => $this->normalName($name))
            ->all();

        return DB::table('efficiency_daily_facts')
            ->join('projects', 'projects.id', '=', 'efficiency_daily_facts.project_id')
            ->leftJoin('equipments', 'equipments.wialon_unit_id', '=', 'efficiency_daily_facts.wialon_unit_id')
            ->whereBetween('efficiency_daily_facts.business_date', [$filters['from'], $filters['to']])
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('efficiency_daily_facts.ownership', $owner))
            ->whereIn('efficiency_daily_facts.vehicle_type', $filters['vehicle_types'])
            ->where('efficiency_daily_facts.source_report_name', $this->sourceReportName())
            ->select([
                'efficiency_daily_facts.project_id',
                'efficiency_daily_facts.ownership',
                'efficiency_daily_facts.wialon_group_id',
                'efficiency_daily_facts.wialon_unit_id',
                'efficiency_daily_facts.unit_name',
                'efficiency_daily_facts.vehicle_type',
                'efficiency_daily_facts.business_date',
                'efficiency_daily_facts.engine_hours_decimal',
                'efficiency_daily_facts.raw_row_json',
                'projects.name as project',
                DB::raw("NULLIF(equipments.registration_number, '') as registration_number"),
            ])
            ->get()
            ->map(function (object $row): object {
                $resolved = $this->projectResolver->resolve([
                    'project_id' => $row->project_id,
                    'project' => $row->project,
                    'raw_row_json' => $row->raw_row_json,
                ]);

                $row->project_id = $resolved['project_id'];
                $row->project = $resolved['project'];
                $row->project_source = $resolved['source'];

                return $row;
            })
            ->reject(fn (object $row): bool => in_array($this->normalName((string) $row->project), $excludedProjects, true))
            ->values();
    }

    private function resolvedDailyStatsRows(array $filters): Collection
    {
        $excludedProjects = collect(self::EXCLUDED_PROJECT_NAMES)
            ->map(fn (string $name): string => $this->normalName($name))
            ->all();

        return DB::table('equipment_daily_stats')
            ->join('equipments', 'equipments.id', '=', 'equipment_daily_stats.equipment_id')
            ->leftJoin('projects', 'projects.id', '=', 'equipment_daily_stats.project_id')
            ->leftJoin('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']])
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('equipment_daily_stats.ownership_type', $owner))
            ->whereIn('equipment_types.name', $filters['vehicle_types'])
            ->select([
                'equipment_daily_stats.project_id',
                'equipment_daily_stats.ownership_type as ownership',
                'equipments.wialon_unit_id',
                'equipments.name as unit_name',
                'equipment_types.name as vehicle_type',
                'equipment_daily_stats.stat_date as business_date',
                'equipment_daily_stats.worked_hours as engine_hours_decimal',
                'projects.name as project',
                DB::raw("NULLIF(equipments.registration_number, '') as registration_number"),
            ])
            ->get()
            ->map(function (object $row): object {
                $row->project_id = (int) ($row->project_id ?? 0);
                $row->project = (string) ($row->project ?: 'Layihəsiz');
                $row->project_source = 'daily_stats';
                $row->wialon_group_id = null;
                $row->wialon_unit_id = (string) ($row->wialon_unit_id ?: $row->unit_name);
                $row->raw_row_json = null;

                return $row;
            })
            ->reject(fn (object $row): bool => in_array($this->normalName((string) $row->project), $excludedProjects, true))
            ->values();
    }

    /** @param array<string, mixed> $filters */
    private function dailyFactRowsCacheKey(array $filters): string
    {
        return 'monthly_efficiency:daily_fact_rows:'.sha1(json_encode([
            'from' => $filters['from'],
            'to' => $filters['to'],
            'ownership_type' => $filters['ownership_type'],
            'vehicle_types' => $filters['vehicle_types'],
            'source_mode' => $this->sourceMode(),
            'source_report_name' => $this->sourceReportName(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function unitRow(object $row): array
    {
        return [
            'number' => null,
            'name' => $row->unit_name,
            'registration_number' => $row->registration_number ?: $row->unit_name,
            'vehicle_type' => $row->vehicle_type,
            'project' => $row->project,
            'project_source' => $row->project_source,
            'project_source_label' => $this->projectSourceLabel((string) $row->project_source),
            'period' => $row->period_from.' - '.$row->period_to,
            'period_from' => $row->period_from,
            'period_to' => $row->period_to,
            'synced_days_count' => (int) $row->synced_days_count,
            'current_hours_decimal' => (float) $row->current_hours,
            'current_hours' => number_format((float) $row->current_hours, 2, '.', ''),
            'normative_hours_decimal' => self::NORMATIVE_HOURS,
            'normative_hours' => number_format(self::NORMATIVE_HOURS, 0, '.', ''),
            'efficiency_percent_decimal' => (float) $row->efficiency_percent,
            'efficiency_percent' => number_format((float) $row->efficiency_percent, 2, '.', '').'%',
            'ownership' => $this->ownershipLabel($row->ownership),
            'status' => $row->monthly_status,
            'status_label' => MonthlyEfficiencyStatus::labels()[$row->monthly_status] ?? $row->monthly_status,
            'wialon_unit_id' => $row->wialon_unit_id,
        ];
    }

    private function paginateCollection(Collection $rows, array $filters): LengthAwarePaginator
    {
        $page = $filters['page'];
        $perPage = $filters['per_page'];

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()?->url()]
        );
    }

    private function usesProjectScope(array $filters): bool
    {
        return $filters['project_id'] !== null || $filters['project_ids'] !== [];
    }

    /** @return array{month: string, from: string, to: string} */
    private function monthPeriod(array $filters, string $context): array
    {
        $timezone = config('app.timezone');

        if (filled($filters['month'] ?? null)) {
            $month = CarbonImmutable::createFromFormat('Y-m', (string) $filters['month'], $timezone);

            if ($month === false) {
                throw new InvalidArgumentException('Aylıq effektivlik üçün bir təqvim ayı seçilməlidir.');
            }

            $month = $month->startOfMonth();

            return [
                'month' => $month->format('Y-m'),
                'from' => $month->toDateString(),
                'to' => $month->endOfMonth()->toDateString(),
            ];
        }

        $range = $this->dateRangePolicy->normalize([
            ...$filters,
            '_default_from' => now($timezone)->startOfMonth(),
            '_default_to' => now($timezone),
        ], $context);
        $from = CarbonImmutable::parse($range['from'], $timezone)->startOfDay();
        $to = CarbonImmutable::parse($range['to'], $timezone)->startOfDay();

        if (! $from->isSameMonth($to)) {
            throw new InvalidArgumentException('Aylıq effektivlik üçün bir təqvim ayı seçilməlidir.');
        }

        $month = $from->startOfMonth();

        return [
            'month' => $month->format('Y-m'),
            'from' => $month->toDateString(),
            'to' => $month->endOfMonth()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function completeness(array $filters): array
    {
        $expected = collect(CarbonPeriod::create($filters['from'], $filters['to']))
            ->map(fn ($date): string => $date->toDateString())
            ->values();
        $completed = $this->dailyFactRows($filters)
            ->pluck('business_date')
            ->unique()
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->values();
        $missing = $expected->diff($completed)->values();

        return [
            'expected_days' => $expected->count(),
            'completed_days' => $completed->count(),
            'failed_days' => 0,
            'missing_days' => $missing->all(),
            'is_complete' => $missing->isEmpty(),
            'message' => $missing->isEmpty() ? null : 'Seçilmiş ay üzrə məlumatlar tam sinxronlaşdırılmayıb.',
        ];
    }

    private function statusSql(string $column): string
    {
        return "CASE
            WHEN {$column} <= 150 THEN '".MonthlyEfficiencyStatus::CRITICAL_LOW."'
            WHEN {$column} < 200 THEN '".MonthlyEfficiencyStatus::LOW."'
            ELSE '".MonthlyEfficiencyStatus::NORMAL."'
        END";
    }

    private function canonicalStatus(?string $status): ?string
    {
        return match ($status) {
            MonthlyEfficiencyStatus::CRITICAL_LOW, 'kritik_asagi', 'critical' => MonthlyEfficiencyStatus::CRITICAL_LOW,
            MonthlyEfficiencyStatus::LOW, 'asagi' => MonthlyEfficiencyStatus::LOW,
            MonthlyEfficiencyStatus::NORMAL => MonthlyEfficiencyStatus::NORMAL,
            default => null,
        };
    }

    /** @return array<int, string> */
    private function allowedVehicleTypeLabels(): array
    {
        return collect(self::ALLOWED_TYPES)
            ->map(fn (string $type): string => FleetVehicleType::label($type))
            ->all();
    }

    private function ownershipLabel(?string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İcarə' : 'NWC';
    }

    private function projectSourceLabel(string $source): string
    {
        if ($source === '') {
            return '-';
        }

        $labels = [
            'wialon_location' => 'Wialon lokasiya',
            'local_geofence' => 'Lokal geozona',
            'wialon_geofence' => 'Wialon geozona',
            'group_fallback' => 'Wialon qrup fallback',
            'daily_stats' => '24 saat Dashboard cədvəli',
        ];

        return collect(explode(',', $source))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->map(fn (string $part): string => $labels[$part] ?? $part)
            ->unique()
            ->implode(', ');
    }

    private function sourceReportName(): string
    {
        return match ($this->sourceMode()) {
            'daily_stats' => '24 saat Dashboard daily stats',
            'date_report' => (string) config('fleet.wialon.monthly_efficiency_date_report_template_name'),
            default => (string) config('fleet.wialon.monthly_efficiency_group_report_template_name'),
        };
    }

    private function sourceMode(): string
    {
        $source = strtolower(trim((string) config('fleet.wialon.monthly_efficiency_source', 'daily_stats')));

        return in_array($source, ['daily_stats', 'group_report', 'date_report'], true)
            ? $source
            : 'daily_stats';
    }

    private function usesDailyStatsSource(): bool
    {
        return $this->sourceMode() === 'daily_stats';
    }

    private function normalName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/\s+/u', ' ', $name) ?: '';
    }

    /** @return array<string, mixed> */
    private function emptySummary(array $filters): array
    {
        $summary = collect(array_keys(MonthlyEfficiencyStatus::labels()))
            ->mapWithKeys(fn (string $status): array => [$status => 0])
            ->all();
        $summary['total'] = 0;
        $summary['total_current_hours'] = 0.0;
        $summary['total_normative_hours'] = 0.0;
        $summary['efficiency_percent'] = 0.0;
        $summary['completeness'] = [
            'expected_days' => 0,
            'completed_days' => 0,
            'failed_days' => 0,
            'missing_days' => [],
            'is_complete' => true,
            'message' => null,
        ];

        return $summary;
    }
}
