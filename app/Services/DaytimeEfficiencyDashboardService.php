<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Support\DashboardDateRangePolicy;
use App\Support\EfficiencyStatus;
use App\Support\FleetVehicleType;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DaytimeEfficiencyDashboardService
{
    public function __construct(private DashboardDateRangePolicy $dateRangePolicy) {}

    public function isReady(): bool
    {
        return Schema::hasTable('daytime_efficiency_daily_facts');
    }

    /** @return array<string, int> */
    public function summaryForOwnership(array $filters, string $ownership): array
    {
        if (! $this->isReady()) {
            return $this->emptySummary();
        }

        $counts = $this->baseQuery([...$filters, 'ownership_type' => $ownership])
            ->selectRaw('efficiency_status, COUNT(*) total')
            ->groupBy('efficiency_status')
            ->pluck('total', 'efficiency_status');
        $summary = collect(EfficiencyStatus::labels())
            ->mapWithKeys(fn (string $label, string $status): array => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
        $summary['total'] = array_sum($summary);

        return $summary;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function projectRowsByOwnership(array $filters): array
    {
        $result = [Equipment::OWNERSHIP_NWC => [], Equipment::OWNERSHIP_ICARE => []];

        if (! $this->isReady()) {
            return $result;
        }

        $rows = $this->baseQuery($filters)
            ->join('projects', 'projects.id', '=', 'daytime_efficiency_daily_facts.project_id')
            ->select('projects.id', 'projects.name', 'daytime_efficiency_daily_facts.ownership')
            ->selectRaw($this->statusCountSql())
            ->selectRaw('COUNT(*) total')
            ->groupBy('projects.id', 'projects.name', 'daytime_efficiency_daily_facts.ownership')
            ->orderByDesc('total')
            ->get();

        foreach ($rows as $row) {
            $item = ['id' => (int) $row->id, 'name' => $row->name, 'total' => (int) $row->total];

            foreach (array_keys(EfficiencyStatus::labels()) as $status) {
                $item[$status] = (int) $row->{$status};
            }

            $result[$row->ownership][] = $item;
        }

        return $result;
    }

    /** @return array<int, array{status: string, label: string, count: int}> */
    public function summary(array $filters): array
    {
        if (! $this->isReady()) {
            return collect(EfficiencyStatus::labels())
                ->map(fn (string $label, string $status): array => ['status' => $status, 'label' => $label, 'count' => 0])
                ->values()
                ->all();
        }

        $counts = $this->baseQuery($filters)
            ->selectRaw('efficiency_status, COUNT(*) total')
            ->groupBy('efficiency_status')
            ->pluck('total', 'efficiency_status');

        return collect(EfficiencyStatus::labels())
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
        $query = $this->baseQuery($filters)
            ->join('projects', 'projects.id', '=', 'daytime_efficiency_daily_facts.project_id')
            ->select('projects.id as project_id', 'projects.name as project', 'daytime_efficiency_daily_facts.ownership')
            ->selectRaw('daytime_efficiency_daily_facts.efficiency_status as status')
            ->selectRaw('COUNT(*) as equipment_days_count')
            ->selectRaw('COUNT(DISTINCT daytime_efficiency_daily_facts.wialon_unit_id) as unique_units_count')
            ->selectRaw('ROUND(AVG(daytime_efficiency_daily_facts.engine_hours_decimal), 2) as average_engine_hours')
            ->groupBy('projects.id', 'projects.name', 'daytime_efficiency_daily_facts.ownership', 'daytime_efficiency_daily_facts.efficiency_status');

        return $query->orderByDesc('equipment_days_count')->orderBy('projects.name')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->through(fn (object $row): array => [
                'project_id' => (int) $row->project_id,
                'project' => $row->project,
                'ownership' => $this->ownershipLabel($row->ownership),
                'status' => EfficiencyStatus::labels()[$row->status] ?? $row->status,
                'equipment_days_count' => (int) $row->equipment_days_count,
                'unique_units_count' => (int) $row->unique_units_count,
                'average_engine_hours' => number_format((float) $row->average_engine_hours, 2, '.', ''),
            ]);
    }

    public function paginateUnits(array $filters): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $query = $this->detailQuery($filters);
        $sorts = [
            'date' => 'daytime_efficiency_daily_facts.business_date',
            'name' => 'daytime_efficiency_daily_facts.unit_name',
            'project' => 'projects.name',
            'vehicle_type' => 'daytime_efficiency_daily_facts.vehicle_type',
            'ownership' => 'daytime_efficiency_daily_facts.ownership',
            'engine_hours' => 'daytime_efficiency_daily_facts.engine_seconds',
            'mileage' => 'daytime_efficiency_daily_facts.mileage_km',
            'status' => 'daytime_efficiency_daily_facts.efficiency_status',
        ];
        $sort = $sorts[$filters['sort']] ?? $sorts['date'];

        return $query->orderBy($sort, $filters['direction'])->orderBy('daytime_efficiency_daily_facts.unit_name')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->through(fn (object $row): array => $this->detailRow($row));
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->paginateUnits($filters);
    }

    /** @return array<int, array<string, mixed>> */
    public function exportRows(array $filters): array
    {
        $filters = $this->normalizeFilters($filters, 'export');

        return $this->detailQuery($filters)
            ->orderBy('daytime_efficiency_daily_facts.business_date')
            ->orderBy('daytime_efficiency_daily_facts.unit_name')
            ->get()
            ->map(fn (object $row): array => $this->detailRow($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public function export(array $filters): array
    {
        $filters = $this->normalizeFilters($filters, 'export');
        $summary = $this->baseQuery($filters)
            ->select('efficiency_status')
            ->selectRaw('COUNT(*) equipment_days_count')
            ->selectRaw('COUNT(DISTINCT wialon_unit_id) unique_units_count')
            ->selectRaw('ROUND(AVG(engine_hours_decimal), 2) average_engine_hours')
            ->groupBy('efficiency_status')
            ->get()
            ->keyBy('efficiency_status');
        $summaryRows = collect(EfficiencyStatus::labels())->map(function (string $label, string $status) use ($summary): array {
            $row = $summary->get($status);

            return [$label, (int) ($row->equipment_days_count ?? 0), (int) ($row->unique_units_count ?? 0), number_format((float) ($row->average_engine_hours ?? 0), 2, '.', '')];
        })->values()->all();
        $detailRows = $this->detailQuery($filters)
            ->orderBy('daytime_efficiency_daily_facts.business_date')
            ->orderBy('daytime_efficiency_daily_facts.unit_name')
            ->get()
            ->map(fn (object $row): array => [
                $row->business_date,
                $row->unit_name,
                $row->project,
                $this->ownershipLabel($row->ownership),
                $row->vehicle_type,
                number_format((float) $row->engine_hours_decimal, 2, '.', ''),
                $row->started_at,
                $row->ended_at,
                $row->mileage_km === null ? '' : number_format((float) $row->mileage_km, 2, '.', ''),
                EfficiencyStatus::labels()[$row->efficiency_status] ?? $row->efficiency_status,
            ])->all();
        $filterRows = [
            ['Dövr', $filters['from'].' - '.$filters['to']],
            ['Ownership', $filters['ownership_type'] ? $this->ownershipLabel($filters['ownership_type']) : 'Hamısı'],
            ['Hesablama vahidi', 'Texnika-gün'],
            ['İş vaxtı', '08:00-17:59'],
            ['Mənbə', 'day report Engine hours (api)'],
        ];
        $sections = [
            ['title' => 'Xülasə', 'columns' => ['Status', 'Texnika-gün sayı', 'Unikal texnika sayı', 'Orta Engine hours'], 'rows' => $summaryRows],
            ['title' => 'Gündüz detalları', 'columns' => ['Tarix', 'Texnika', 'Layihə', 'Ownership', 'Texnika növü', 'Engine hours', 'Başlama', 'Bitmə', 'Yürüş', 'Status'], 'rows' => $detailRows],
        ];

        return [
            'filename' => 'effektivlik-gunduz-'.$filters['from'].'-'.$filters['to'].'.xlsx',
            'title' => 'Gündüz növbəsi üzrə effektivlik',
            'filters' => $filterRows,
            'sections' => $sections,
            'sheets' => [
                ['name' => 'Xülasə', 'title' => 'Gündüz növbəsi üzrə effektivlik', 'filters' => $filterRows, 'sections' => [$sections[0]]],
                ['name' => 'Gündüz detalları', 'title' => 'Gündüz növbəsi üzrə effektivlik', 'filters' => $filterRows, 'sections' => [$sections[1]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function normalizeFilters(array $filters, string $context = 'dashboard'): array
    {
        $range = $this->dateRangePolicy->normalize([
            ...$filters,
            '_default_from' => now(config('app.timezone'))->startOfMonth(),
            '_default_to' => now(config('app.timezone')),
        ], $context);
        $ownership = $filters['ownership_type'] ?? $filters['ownership'] ?? null;
        $ownership = match (mb_strtolower((string) $ownership)) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => null,
        };
        $status = $filters['status'] ?? $filters['work_category'] ?? $filters['day_status'] ?? null;
        $status = $this->canonicalStatus($status);
        $hasVisibleStatusRestriction = array_key_exists('visible_statuses', $filters);
        $visibleStatuses = collect($filters['visible_statuses'] ?? [])
            ->map(fn ($visibleStatus): ?string => $this->canonicalStatus((string) $visibleStatus))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $vehicleTypes = collect($filters['vehicle_types'] ?? [$filters['vehicle_type'] ?? null])
            ->filter()
            ->map(fn ($type): string => FleetVehicleType::label((string) $type))
            ->unique()->values()->all();

        if (filled($filters['equipment_type_id'] ?? null)) {
            $type = EquipmentType::query()->whereKey($filters['equipment_type_id'])->value('name');
            $vehicleTypes = $type ? [FleetVehicleType::label($type)] : [];
        }

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'project_ids' => collect($filters['project_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->all(),
            'ownership_type' => $ownership,
            'vehicle_types' => $vehicleTypes,
            'status' => $status,
            'visible_statuses' => $hasVisibleStatusRestriction ? $visibleStatuses : null,
            'search' => trim((string) ($filters['search'] ?? $filters['unit_name'] ?? '')),
            'sort' => (string) ($filters['sort'] ?? 'date'),
            'direction' => ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($filters['per_page'] ?? 20))),
        ];
    }

    private function baseQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);

        return DB::table('daytime_efficiency_daily_facts')
            ->whereDate('business_date', '>=', $filters['from'])
            ->whereDate('business_date', '<=', $filters['to'])
            ->when($filters['project_id'], fn (Builder $query, int $id): Builder => $query->where('project_id', $id))
            ->when($filters['project_ids'], fn (Builder $query, array $ids): Builder => $query->whereIn('project_id', $ids))
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('ownership', $owner))
            ->when($filters['vehicle_types'], fn (Builder $query, array $types): Builder => $query->whereIn('vehicle_type', $types))
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->where('efficiency_status', $status))
            ->when($filters['status'] === null && is_array($filters['visible_statuses']), fn (Builder $query): Builder => $filters['visible_statuses'] === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('efficiency_status', $filters['visible_statuses']))
            ->when($filters['search'] !== '', fn (Builder $query): Builder => $query->where('unit_name', 'like', '%'.$filters['search'].'%'));
    }

    private function detailQuery(array $filters): Builder
    {
        return $this->baseQuery($filters)
            ->join('projects', 'projects.id', '=', 'daytime_efficiency_daily_facts.project_id')
            ->select('daytime_efficiency_daily_facts.*', 'projects.name as project');
    }

    /** @return array<string, mixed> */
    private function detailRow(object $row): array
    {
        return [
            'date' => $row->business_date,
            'name' => $row->unit_name,
            'project' => $row->project,
            'vehicle_type' => $row->vehicle_type,
            'ownership' => $this->ownershipLabel($row->ownership),
            'engine_hours_decimal' => (float) $row->engine_hours_decimal,
            'engine_hours' => number_format((float) $row->engine_hours_decimal, 2, '.', '').' saat',
            'engine_seconds' => (int) $row->engine_seconds,
            'started_at' => $row->started_at,
            'ended_at' => $row->ended_at,
            'mileage_km' => $row->mileage_km === null ? null : (float) $row->mileage_km,
            'mileage' => $row->mileage_km === null ? '-' : number_format((float) $row->mileage_km, 2, '.', '').' km',
            'status' => $row->efficiency_status,
            'status_label' => EfficiencyStatus::labels()[$row->efficiency_status] ?? $row->efficiency_status,
            'wialon_unit_id' => $row->wialon_unit_id,
        ];
    }

    private function statusCountSql(): string
    {
        return collect(array_keys(EfficiencyStatus::labels()))
            ->map(fn (string $status): string => "SUM(CASE WHEN efficiency_status = '{$status}' THEN 1 ELSE 0 END) as `{$status}`")
            ->implode(', ');
    }

    private function canonicalStatus(?string $status): ?string
    {
        return match ($status) {
            '0_1', 'less_than_1', 'less_than_1_hour' => EfficiencyStatus::ZERO_TO_ONE,
            '1_7', 'from_1_to_7', 'less_than_7_hours' => EfficiencyStatus::ONE_TO_SEVEN,
            '7_10', 'from_7_to_10', 'between_7_and_10_hours' => EfficiencyStatus::SEVEN_TO_TEN,
            'over_10', 'over_10_hours', 'over_10_day_hours' => EfficiencyStatus::OVER_TEN,
            'no_data' => EfficiencyStatus::NO_DATA,
            default => null,
        };
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İcarə' : 'NWC';
    }

    /** @return array<string, int> */
    private function emptySummary(): array
    {
        $summary = collect(array_keys(EfficiencyStatus::labels()))
            ->mapWithKeys(fn (string $status): array => [$status => 0])
            ->all();
        $summary['total'] = 0;

        return $summary;
    }
}
