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

class NightDayEfficiencyDashboardService
{
    public function __construct(private DashboardDateRangePolicy $dateRangePolicy) {}

    public function isReady(): bool
    {
        return Schema::hasTable('night_day_efficiency_daily_facts');
    }

    /** @return array<string, int> */
    public function summaryForOwnership(array $filters, string $ownership): array
    {
        if (! $this->isReady()) {
            return $this->emptySummary();
        }

        $counts = DB::query()
            ->fromSub($this->unitRowsQuery([...$filters, 'ownership_type' => $ownership]), 'final_units')
            ->select('final_status')
            ->selectRaw('COUNT(*) total')
            ->groupBy('final_status')
            ->pluck('total', 'final_status');
        $summary = collect(EfficiencyStatus::labels())
            ->mapWithKeys(fn (string $label, string $status): array => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
        $summary['total'] = array_sum($summary);

        return $summary;
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

        $counts = DB::query()
            ->fromSub($this->unitRowsQuery($filters), 'final_units')
            ->select('final_status')
            ->selectRaw('COUNT(*) total')
            ->groupBy('final_status')
            ->pluck('total', 'final_status');

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
        $query = DB::query()
            ->fromSub($this->unitRowsQuery($filters), 'final_units')
            ->select('project_id', 'project', 'ownership')
            ->selectRaw('final_status as status')
            ->selectRaw('COUNT(*) as unique_units_count')
            ->selectRaw('MAX(synced_days_count) as synced_days_count')
            ->selectRaw('ROUND(AVG(average_engine_hours), 2) as average_engine_hours')
            ->groupBy('project_id', 'project', 'ownership', 'final_status');

        return $query->orderByDesc('unique_units_count')->orderBy('project')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->through(fn (object $row): array => [
                'project_id' => (int) $row->project_id,
                'project' => $row->project,
                'ownership' => $this->ownershipLabel($row->ownership),
                'status' => EfficiencyStatus::labels()[$row->status] ?? $row->status,
                'unique_units_count' => (int) $row->unique_units_count,
                'synced_days_count' => (int) $row->synced_days_count,
                'average_engine_hours' => number_format((float) $row->average_engine_hours, 2, '.', ''),
            ]);
    }

    public function paginateUnits(array $filters): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $query = $this->unitRowsQuery($filters);
        $sorts = [
            'date' => 'period_from',
            'name' => 'unit_name',
            'project' => 'project',
            'vehicle_type' => 'vehicle_type',
            'ownership' => 'ownership',
            'engine_hours' => 'average_engine_seconds',
            'mileage' => 'total_mileage_km',
            'status' => 'final_status',
        ];
        $sort = $sorts[$filters['sort']] ?? $sorts['name'];

        return $query->orderBy($sort, $filters['direction'])->orderBy('unit_name')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->through(fn (object $row): array => $this->unitRow($row));
    }

    /** @return array<int, array<string, mixed>> */
    public function exportRows(array $filters): array
    {
        $filters = $this->normalizeFilters($filters, 'export');

        return $this->unitRowsQuery($filters)
            ->orderBy('project')
            ->orderBy('unit_name')
            ->get()
            ->map(fn (object $row): array => $this->unitRow($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public function export(array $filters): array
    {
        $filters = $this->normalizeFilters($filters, 'export');
        $summary = DB::query()
            ->fromSub($this->unitRowsQuery($filters), 'final_units')
            ->select('final_status')
            ->selectRaw('COUNT(*) unique_units_count')
            ->selectRaw('ROUND(AVG(average_engine_hours), 2) average_engine_hours')
            ->groupBy('final_status')
            ->get()
            ->keyBy('final_status');
        $summaryRows = collect(EfficiencyStatus::labels())->map(function (string $label, string $status) use ($summary): array {
            $row = $summary->get($status);

            return [$label, (int) ($row->unique_units_count ?? 0), number_format((float) ($row->average_engine_hours ?? 0), 2, '.', '')];
        })->values()->all();
        $unitRows = $this->unitRowsQuery($filters)
            ->orderBy('project')
            ->orderBy('unit_name')
            ->get()
            ->map(fn (object $row): array => [
                $row->unit_name,
                $row->project,
                $this->ownershipLabel($row->ownership),
                $row->vehicle_type,
                $row->period_from.' - '.$row->period_to,
                (int) $row->synced_days_count,
                number_format((float) $row->total_engine_hours, 2, '.', ''),
                number_format((float) $row->average_engine_hours, 2, '.', ''),
                EfficiencyStatus::labels()[$row->final_status] ?? $row->final_status,
            ])->all();
        $detailRows = $this->dailyDetailQuery($filters)
            ->orderBy('night_day_efficiency_daily_facts.business_date')
            ->orderBy('night_day_efficiency_daily_facts.unit_name')
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
            ['Hesablama vahidi', 'Unikal texnika'],
            ['İş vaxtı', '00:00-07:59 və 18:00-23:59'],
            ['Mənbə', 'night day report Engine hours (api)'],
        ];
        $sections = [
            ['title' => 'Xülasə', 'columns' => ['Status', 'Unikal texnika sayı', 'Orta Engine hours'], 'rows' => $summaryRows],
            ['title' => 'Texnika üzrə', 'columns' => ['Texnika', 'Layihə', 'Ownership', 'Texnika növü', 'Dövr', 'Sinxronlaşdırılmış gün sayı', 'Ümumi Engine hours', 'Orta Engine hours', 'Status'], 'rows' => $unitRows],
            ['title' => 'Gündəlik detallar', 'columns' => ['Tarix', 'Texnika', 'Layihə', 'Ownership', 'Texnika növü', 'Engine hours', 'Başlama', 'Bitmə', 'Yürüş', 'Status'], 'rows' => $detailRows],
        ];

        return [
            'filename' => 'effektivlik-gece-gun-daxilinde-'.$filters['from'].'-'.$filters['to'].'.xlsx',
            'title' => 'Gün daxilində gecə effektivliyi',
            'filters' => $filterRows,
            'sections' => $sections,
            'sheets' => [
                ['name' => 'Xülasə', 'title' => 'Gün daxilində gecə effektivliyi', 'filters' => $filterRows, 'sections' => [$sections[0]]],
                ['name' => 'Texnika üzrə', 'title' => 'Gün daxilində gecə effektivliyi', 'filters' => $filterRows, 'sections' => [$sections[1]]],
                ['name' => 'Gündəlik detallar', 'title' => 'Gün daxilində gecə effektivliyi', 'filters' => $filterRows, 'sections' => [$sections[2]]],
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
            'sort' => (string) ($filters['sort'] ?? 'name'),
            'direction' => ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($filters['per_page'] ?? 20))),
        ];
    }

    private function unitRowsQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);
        $sub = DB::table('night_day_efficiency_daily_facts')
            ->join('projects', 'projects.id', '=', 'night_day_efficiency_daily_facts.project_id')
            ->whereDate('night_day_efficiency_daily_facts.business_date', '>=', $filters['from'])
            ->whereDate('night_day_efficiency_daily_facts.business_date', '<=', $filters['to'])
            ->when($filters['project_id'], fn (Builder $query, int $id): Builder => $query->where('night_day_efficiency_daily_facts.project_id', $id))
            ->when($filters['project_ids'], fn (Builder $query, array $ids): Builder => $query->whereIn('night_day_efficiency_daily_facts.project_id', $ids))
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('night_day_efficiency_daily_facts.ownership', $owner))
            ->when($filters['vehicle_types'], fn (Builder $query, array $types): Builder => $query->whereIn('night_day_efficiency_daily_facts.vehicle_type', $types))
            ->select('night_day_efficiency_daily_facts.project_id', 'night_day_efficiency_daily_facts.ownership', 'night_day_efficiency_daily_facts.wialon_unit_id')
            ->selectRaw('MAX(projects.name) as project')
            ->selectRaw('MAX(night_day_efficiency_daily_facts.unit_name) as unit_name')
            ->selectRaw('MAX(night_day_efficiency_daily_facts.vehicle_type) as vehicle_type')
            ->selectRaw('MIN(night_day_efficiency_daily_facts.business_date) as period_from')
            ->selectRaw('MAX(night_day_efficiency_daily_facts.business_date) as period_to')
            ->selectRaw('COUNT(DISTINCT night_day_efficiency_daily_facts.business_date) as synced_days_count')
            ->selectRaw('ROUND(SUM(night_day_efficiency_daily_facts.engine_hours_decimal), 2) as total_engine_hours')
            ->selectRaw('SUM(night_day_efficiency_daily_facts.engine_seconds) as total_engine_seconds')
            ->selectRaw('ROUND(SUM(night_day_efficiency_daily_facts.engine_hours_decimal) / NULLIF(COUNT(DISTINCT night_day_efficiency_daily_facts.business_date), 0), 2) as average_engine_hours')
            ->selectRaw('ROUND(SUM(night_day_efficiency_daily_facts.engine_seconds) / NULLIF(COUNT(DISTINCT night_day_efficiency_daily_facts.business_date), 0)) as average_engine_seconds')
            ->selectRaw('MIN(night_day_efficiency_daily_facts.started_at) as started_at')
            ->selectRaw('MAX(night_day_efficiency_daily_facts.ended_at) as ended_at')
            ->selectRaw('ROUND(SUM(night_day_efficiency_daily_facts.mileage_km), 2) as total_mileage_km')
            ->groupBy('night_day_efficiency_daily_facts.project_id', 'night_day_efficiency_daily_facts.ownership', 'night_day_efficiency_daily_facts.wialon_unit_id');

        $statusSql = $this->statusSql('average_engine_seconds');
        $query = DB::query()
            ->fromSub($sub, 'unit_rows')
            ->select('unit_rows.*')
            ->selectRaw($statusSql.' as final_status')
            ->when($filters['search'] !== '', fn (Builder $query): Builder => $query->where('unit_name', 'like', '%'.$filters['search'].'%'))
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->whereRaw($statusSql.' = ?', [$status]))
            ->when($filters['status'] === null && is_array($filters['visible_statuses']), fn (Builder $query): Builder => $filters['visible_statuses'] === []
                ? $query->whereRaw('1 = 0')
                : $query->whereRaw($statusSql.' in ('.implode(',', array_fill(0, count($filters['visible_statuses']), '?')).')', $filters['visible_statuses']));

        return $query;
    }

    private function dailyDetailQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters, 'export');
        $finalUnits = $this->unitRowsQuery($filters)->select('wialon_unit_id');

        return DB::table('night_day_efficiency_daily_facts')
            ->join('projects', 'projects.id', '=', 'night_day_efficiency_daily_facts.project_id')
            ->select('night_day_efficiency_daily_facts.*', 'projects.name as project')
            ->whereDate('night_day_efficiency_daily_facts.business_date', '>=', $filters['from'])
            ->whereDate('night_day_efficiency_daily_facts.business_date', '<=', $filters['to'])
            ->whereIn('night_day_efficiency_daily_facts.wialon_unit_id', $finalUnits)
            ->when($filters['project_id'], fn (Builder $query, int $id): Builder => $query->where('night_day_efficiency_daily_facts.project_id', $id))
            ->when($filters['project_ids'], fn (Builder $query, array $ids): Builder => $query->whereIn('night_day_efficiency_daily_facts.project_id', $ids))
            ->when($filters['ownership_type'], fn (Builder $query, string $owner): Builder => $query->where('night_day_efficiency_daily_facts.ownership', $owner))
            ->when($filters['vehicle_types'], fn (Builder $query, array $types): Builder => $query->whereIn('night_day_efficiency_daily_facts.vehicle_type', $types))
            ->when($filters['search'] !== '', fn (Builder $query): Builder => $query->where('night_day_efficiency_daily_facts.unit_name', 'like', '%'.$filters['search'].'%'));
    }

    /** @return array<string, mixed> */
    private function unitRow(object $row): array
    {
        return [
            'date' => $row->period_from.' - '.$row->period_to,
            'period' => $row->period_from.' - '.$row->period_to,
            'name' => $row->unit_name,
            'project' => $row->project,
            'vehicle_type' => $row->vehicle_type,
            'ownership' => $this->ownershipLabel($row->ownership),
            'synced_days_count' => (int) $row->synced_days_count,
            'total_engine_hours_decimal' => (float) $row->total_engine_hours,
            'total_engine_hours' => number_format((float) $row->total_engine_hours, 2, '.', '').' saat',
            'average_engine_hours_decimal' => (float) $row->average_engine_hours,
            'average_engine_hours' => number_format((float) $row->average_engine_hours, 2, '.', '').' saat',
            'engine_hours_decimal' => (float) $row->average_engine_hours,
            'engine_hours' => number_format((float) $row->average_engine_hours, 2, '.', '').' saat',
            'engine_seconds' => (int) $row->average_engine_seconds,
            'started_at' => $row->started_at,
            'ended_at' => $row->ended_at,
            'mileage_km' => $row->total_mileage_km === null ? null : (float) $row->total_mileage_km,
            'mileage' => $row->total_mileage_km === null ? '-' : number_format((float) $row->total_mileage_km, 2, '.', '').' km',
            'status' => $row->final_status,
            'status_label' => EfficiencyStatus::labels()[$row->final_status] ?? $row->final_status,
            'wialon_unit_id' => $row->wialon_unit_id,
        ];
    }

    private function statusSql(string $column): string
    {
        return "CASE
            WHEN {$column} <= 0 OR {$column} IS NULL THEN '".EfficiencyStatus::NO_DATA."'
            WHEN {$column} < 3600 THEN '".EfficiencyStatus::ZERO_TO_ONE."'
            WHEN {$column} < 25200 THEN '".EfficiencyStatus::ONE_TO_SEVEN."'
            WHEN {$column} <= 36000 THEN '".EfficiencyStatus::SEVEN_TO_TEN."'
            ELSE '".EfficiencyStatus::OVER_TEN."'
        END";
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
