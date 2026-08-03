<?php

namespace App\Services;

use App\Models\EquipmentType;
use App\Models\GeofenceViolationReportRow;
use App\Support\GeofenceExcludedGroups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GeofenceViolationsDashboardService
{
    public function __construct(private GeofenceExcludedGroups $excludedGroups) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getDashboard(array $filters): array
    {
        $query = $this->filteredQuery($filters);
        $rows = (clone $query)
            ->orderByDesc('is_active')
            ->orderByDesc('outside_duration_seconds')
            ->orderBy('equipment_name')
            ->paginate(max(1, (int) config('geofence_violations.per_page', 25)))
            ->withQueryString();

        return [
            'rows' => $rows,
            ...$this->summaryCached($filters),
            'projects' => $this->facetQuery()
                ->whereNotNull('project_id')
                ->whereNotNull('project_name')
                ->select(['project_id', 'project_name'])
                ->distinct()
                ->orderBy('project_name')
                ->get(),
            'equipment_types' => config('geofence_violations.allowed_equipment_types', []),
        ];
    }

    /**
     * Keeps the new report-backed widget independent from DashboardService.
     *
     * @param  array<string, mixed>  $dashboardFilters
     * @return array<string, mixed>
     */
    public function getDashboardWidget(array $dashboardFilters): array
    {
        $equipmentType = filled($dashboardFilters['equipment_type_id'] ?? null)
            ? EquipmentType::query()->whereKey((int) $dashboardFilters['equipment_type_id'])->value('name')
            : null;

        $filters = [
            'date_from' => (string) $dashboardFilters['from'],
            'date_to' => (string) $dashboardFilters['to'],
            'project_id' => filled($dashboardFilters['project_id'] ?? null)
                ? (int) $dashboardFilters['project_id']
                : null,
            'equipment_type' => $equipmentType,
            'ownership_type' => filled($dashboardFilters['ownership_type'] ?? null)
                ? (string) $dashboardFilters['ownership_type']
                : null,
            'status' => null,
            'search' => '',
        ];

        if ($equipmentType !== null && ! in_array($equipmentType, config('geofence_violations.allowed_equipment_types', []), true)) {
            return $this->emptySummary();
        }

        return $this->summaryCached($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters): Builder
    {
        $timezone = config('app.timezone', 'Asia/Baku');
        $from = Carbon::createFromFormat('Y-m-d', $filters['date_from'], $timezone)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $filters['date_to'], $timezone)->endOfDay();
        $query = $this->facetQuery()
            ->where('exited_at', '<=', $to)
            ->where('last_confirmed_at', '>=', $from);

        if ($filters['project_id'] !== null) {
            $query->where('project_id', $filters['project_id']);
        }

        if ($filters['equipment_type'] !== null) {
            $query->where('equipment_type', $filters['equipment_type']);
        }

        if ($filters['ownership_type'] !== null) {
            $query->where('ownership_type', $filters['ownership_type']);
        }

        if ($filters['status'] !== null) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        if ($filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('equipment_name', 'like', $search)
                    ->orWhere('wialon_unit_id', 'like', $search)
                    ->orWhere('last_project_geofence', 'like', $search)
                    ->orWhere('last_location', 'like', $search);
            });
        }

        return $query;
    }

    public function paginateRows(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('is_active')
            ->orderByDesc('outside_duration_seconds')
            ->orderBy('equipment_name')
            ->paginate(max(1, min(100, $perPage)))
            ->withQueryString();
    }

    public function formatDuration(int $seconds): string
    {
        $row = new GeofenceViolationReportRow(['outside_duration_seconds' => $seconds]);

        return $row->duration_label;
    }

    private function facetQuery(): Builder
    {
        $excludedGroupIds = $this->excludedGroups->projectWialonGroupIds();
        $excludedProjectIds = $this->excludedGroups->projectIdsWithOnlyExcludedGroups();

        return GeofenceViolationReportRow::query()
            ->whereHas('project', fn (Builder $query) => $query
                ->where('active', true)
                ->excludeFromOperationalDashboard())
            ->when($excludedGroupIds !== [], function (Builder $query) use ($excludedGroupIds): void {
                $query->where(function (Builder $query) use ($excludedGroupIds): void {
                    $query->whereNull('project_wialon_group_id')
                        ->orWhereNotIn('project_wialon_group_id', $excludedGroupIds);
                });
            })
            ->when($excludedProjectIds !== [], fn (Builder $query) => $query->whereNotIn('project_id', $excludedProjectIds))
            ->where(function (Builder $query): void {
                $query->whereNull('equipment_id')
                    ->orWhereHas('equipment', fn (Builder $query): Builder => $this->excludedGroups->applyAllowedUnits($query));
            })
            ->where('report_name', (string) config(
                'geofence_violations.report_name',
                GeofenceViolationReportRow::REPORT_NAME
            ))
            ->whereIn('equipment_type', config('geofence_violations.allowed_equipment_types', []))
            ->where('outside_duration_seconds', '>', (int) config(
                'geofence_violations.minimum_duration_seconds',
                GeofenceViolationReportRow::MINIMUM_DURATION_SECONDS
            ))
            ->whereNotNull('report_period_from')
            ->whereNotNull('report_period_to')
            ->whereNotNull('exited_at')
            ->whereNotNull('last_confirmed_at')
            ->whereColumn('last_confirmed_at', '>=', 'exited_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Builder $query): array
    {
        return [
            'distribution' => $this->projectDistribution(clone $query),
            'kpis' => [
                'total_violations' => (clone $query)->count(),
                'active_violations' => $this->uniqueEquipmentCount(
                    (clone $query)->where('is_active', true)
                ),
                'active_projects' => $this->uniqueProjectCount(clone $query),
                'longest_duration_seconds' => (int) ((clone $query)->max('outside_duration_seconds') ?? 0),
            ],
            'latest_report_at' => (clone $query)->max('report_generated_at'),
            'latest_report_period_to' => (clone $query)->max('report_period_to'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summaryCached(array $filters): array
    {
        $version = (string) Cache::get('geofence_violations:data_version', 'empty');
        $key = 'geofence_violations:summary:'.sha1(json_encode([
            'version' => $version,
            'filters' => $filters,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember(
            $key,
            max(1, (int) config('geofence_violations.summary_cache_seconds', 300)),
            fn (): array => $this->summary($this->filteredQuery($filters))
        );
    }

    private function uniqueEquipmentCount(Builder $query): int
    {
        $knownEquipment = (clone $query)
            ->whereNotNull('equipment_id')
            ->distinct()
            ->count('equipment_id');
        $fallback = (clone $query)
            ->whereNull('equipment_id')
            ->selectRaw("LOWER(COALESCE(NULLIF(wialon_unit_id, ''), equipment_name)) AS identity")
            ->distinct();

        return $knownEquipment + DB::query()
            ->fromSub($fallback, 'geofence_violation_equipment')
            ->whereNotNull('identity')
            ->count();
    }

    private function uniqueProjectCount(Builder $query): int
    {
        $knownProjects = (clone $query)
            ->whereNotNull('project_id')
            ->distinct()
            ->count('project_id');
        $fallback = (clone $query)
            ->whereNull('project_id')
            ->whereNotNull('project_name')
            ->selectRaw("LOWER(NULLIF(project_name, '')) AS identity")
            ->distinct();

        return $knownProjects + DB::query()
            ->fromSub($fallback, 'geofence_violation_projects')
            ->whereNotNull('identity')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'distribution' => collect(),
            'kpis' => [
                'total_violations' => 0,
                'active_violations' => 0,
                'active_projects' => 0,
                'longest_duration_seconds' => 0,
            ],
            'latest_report_at' => null,
            'latest_report_period_to' => null,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function projectDistribution(Builder $query): Collection
    {
        $rows = $query
            ->select(['project_id', 'project_name'])
            ->selectRaw('COUNT(*) as violation_count')
            ->groupBy('project_id', 'project_name')
            ->orderByDesc('violation_count')
            ->orderBy('project_name')
            ->get();
        $total = (int) $rows->sum('violation_count');
        $palette = [
            '#2563EB',
            '#22C55E',
            '#F59E0B',
            '#7C3AED',
            '#0EA5A8',
            '#EF4444',
            '#0891B2',
            '#64748B',
        ];

        return $rows->values()->map(function (GeofenceViolationReportRow $row, int $index) use ($total, $palette): array {
            $count = (int) $row->violation_count;

            return [
                'project_id' => $row->project_id ? (int) $row->project_id : null,
                'label' => filled($row->project_name) ? $row->project_name : 'Layihə göstərilməyib',
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                'share' => $total > 0 ? ($count / $total) * 100 : 0.0,
                'color' => $palette[$index % count($palette)],
            ];
        });
    }
}
