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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MonthlyEfficiencyDashboardService
{
    private const MODULE_CODE = 'monthly_efficiency';

    private const NORMATIVE_HOURS = 200.0;

    private const ALLOWED_TYPES = [
        FleetVehicleType::BULLDOZER,
        FleetVehicleType::EXCAVATOR,
        FleetVehicleType::DUMP_TRUCK,
    ];

    private const EXCLUDED_PROJECT_NAMES = [
        'Layihəsiz',
        '-Layihəsiz-',
        'Layihesiz',
        '-Layihesiz-',
        'Təmir',
        'Temir',
    ];

    public function __construct(private DashboardDateRangePolicy $dateRangePolicy) {}

    public function isReady(): bool
    {
        return Schema::hasTable('efficiency_daily_facts');
    }

    /** @return array<string, mixed> */
    public function summaryForOwnership(array $filters, string $ownership): array
    {
        if (! $this->isReady()) {
            return $this->emptySummary($filters);
        }

        $filters = $this->normalizeFilters([...$filters, 'ownership_type' => $ownership]);
        $rows = DB::query()
            ->fromSub($this->unitRowsQuery($filters), 'monthly_units')
            ->select('monthly_status')
            ->selectRaw('COUNT(*) total_units')
            ->selectRaw('ROUND(SUM(current_hours), 2) total_current_hours')
            ->groupBy('monthly_status')
            ->get()
            ->keyBy('monthly_status');
        $summary = collect(MonthlyEfficiencyStatus::labels())
            ->mapWithKeys(fn (string $label, string $status): array => [$status => (int) ($rows[$status]->total_units ?? 0)])
            ->all();
        $totalUnits = array_sum($summary);
        $totalCurrentHours = (float) $rows->sum(fn (object $row): float => (float) $row->total_current_hours);
        $totalNormativeHours = $totalUnits * self::NORMATIVE_HOURS;
        $summary['total'] = $totalUnits;
        $summary['total_current_hours'] = round($totalCurrentHours, 2);
        $summary['total_normative_hours'] = round($totalNormativeHours, 2);
        $summary['efficiency_percent'] = $totalNormativeHours > 0
            ? round($totalCurrentHours / $totalNormativeHours * 100, 2)
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

        $counts = DB::query()
            ->fromSub($this->unitRowsQuery($filters), 'monthly_units')
            ->select('monthly_status')
            ->selectRaw('COUNT(*) total')
            ->groupBy('monthly_status')
            ->pluck('total', 'monthly_status');

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
        $query = DB::query()
            ->fromSub($this->unitRowsQuery($filters), 'monthly_units')
            ->select('project_id', 'project', 'ownership', 'monthly_status')
            ->selectRaw('COUNT(*) as unique_units_count')
            ->selectRaw('ROUND(SUM(current_hours), 2) as total_current_hours')
            ->selectRaw('ROUND(COUNT(*) * ?, 2) as total_normative_hours', [self::NORMATIVE_HOURS])
            ->selectRaw('ROUND(SUM(current_hours) * 100.0 / NULLIF(COUNT(*) * ?, 0), 2) as efficiency_percent', [self::NORMATIVE_HOURS])
            ->groupBy('project_id', 'project', 'ownership', 'monthly_status');

        return $query->orderByDesc('unique_units_count')->orderBy('project')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->through(fn (object $row): array => [
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

        return $this->unitRowsQuery($filters)
            ->orderBy($sort, $direction)
            ->orderBy('unit_name')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->through(fn (object $row): array => $this->unitRow($row));
    }

    /** @return array<string, mixed> */
    public function export(array $filters): array
    {
        $filters = $this->normalizeFilters($filters, 'export');
        $unitRows = $this->unitRowsQuery($filters)->orderBy('project')->orderBy('unit_name')->get();
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
                number_format((float) $row->current_hours, 2, '.', ''),
                number_format((float) $row->normative_hours, 2, '.', ''),
                number_format((float) $row->efficiency_percent, 2, '.', '').'%',
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
            ['Mənbə', 'Qrup report Engine hours (api)'],
        ];
        $sections = [
            ['title' => 'Xülasə', 'columns' => ['Ownership', 'Status', 'Unique unit count', 'Total Cari MS', 'Total Normativ MS', 'Effektivlik %'], 'rows' => $summaryRows],
            ['title' => 'Layihələr', 'columns' => ['Layihə', 'Ownership', 'Kritik aşağı', 'Aşağı', 'Normal', 'Total units'], 'rows' => $projectRows],
            ['title' => 'Texnika üzrə', 'columns' => ['№', 'D.Q.N.', 'Texnika tipi', 'Layihə', 'Cari MS', 'Normativ MS', 'Effektivlik %', 'Mənsubiyyət', 'Status'], 'rows' => $detailRows],
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

    private function unitRowsQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);
        $sub = DB::table('efficiency_daily_facts')
            ->join('projects', 'projects.id', '=', 'efficiency_daily_facts.project_id')
            ->leftJoin('equipments', 'equipments.wialon_unit_id', '=', 'efficiency_daily_facts.wialon_unit_id')
            ->whereDate('efficiency_daily_facts.business_date', '>=', $filters['from'])
            ->whereDate('efficiency_daily_facts.business_date', '<=', $filters['to'])
            ->whereNotIn('projects.name', self::EXCLUDED_PROJECT_NAMES)
            ->when($filters['project_id'], fn (Builder $query, int $id): Builder => $query->where('efficiency_daily_facts.project_id', $id))
            ->when($filters['project_ids'], fn (Builder $query, array $ids): Builder => $query->whereIn('efficiency_daily_facts.project_id', $ids))
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('efficiency_daily_facts.ownership', $owner))
            ->whereIn('efficiency_daily_facts.vehicle_type', $filters['vehicle_types'])
            ->select('efficiency_daily_facts.project_id', 'efficiency_daily_facts.ownership', 'efficiency_daily_facts.wialon_unit_id')
            ->selectRaw('MAX(projects.name) as project')
            ->selectRaw('MAX(efficiency_daily_facts.unit_name) as unit_name')
            ->selectRaw("MAX(NULLIF(equipments.registration_number, '')) as registration_number")
            ->selectRaw('MAX(efficiency_daily_facts.vehicle_type) as vehicle_type')
            ->selectRaw('MIN(efficiency_daily_facts.business_date) as period_from')
            ->selectRaw('MAX(efficiency_daily_facts.business_date) as period_to')
            ->selectRaw('COUNT(DISTINCT efficiency_daily_facts.business_date) as synced_days_count')
            ->selectRaw('ROUND(SUM(efficiency_daily_facts.engine_hours_decimal), 2) as current_hours')
            ->selectRaw('? as normative_hours', [self::NORMATIVE_HOURS])
            ->selectRaw('ROUND(SUM(efficiency_daily_facts.engine_hours_decimal) * 100.0 / ?, 2) as efficiency_percent', [self::NORMATIVE_HOURS])
            ->groupBy('efficiency_daily_facts.project_id', 'efficiency_daily_facts.ownership', 'efficiency_daily_facts.wialon_unit_id');
        $statusSql = $this->statusSql('current_hours');

        return DB::query()
            ->fromSub($sub, 'unit_rows')
            ->select('unit_rows.*')
            ->selectRaw($statusSql.' as monthly_status')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): Builder {
                $search = '%'.$filters['search'].'%';

                return $query->where(function (Builder $query) use ($search): void {
                    $query->where('unit_name', 'like', $search)
                        ->orWhere('registration_number', 'like', $search);
                });
            })
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->whereRaw($statusSql.' = ?', [$status]))
            ->when($filters['status'] === null && is_array($filters['visible_statuses']), fn (Builder $query): Builder => $filters['visible_statuses'] === []
                ? $query->whereRaw('1 = 0')
                : $query->whereRaw($statusSql.' in ('.implode(',', array_fill(0, count($filters['visible_statuses']), '?')).')', $filters['visible_statuses']));
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
            'synced_days_count' => (int) $row->synced_days_count,
        ];
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
        $completed = DB::table('efficiency_daily_facts')
            ->join('projects', 'projects.id', '=', 'efficiency_daily_facts.project_id')
            ->whereDate('business_date', '>=', $filters['from'])
            ->whereDate('business_date', '<=', $filters['to'])
            ->whereNotIn('projects.name', self::EXCLUDED_PROJECT_NAMES)
            ->whereIn('vehicle_type', $filters['vehicle_types'])
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('ownership', $owner))
            ->distinct()
            ->pluck('business_date')
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
