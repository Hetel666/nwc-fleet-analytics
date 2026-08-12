<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Support\DashboardDateRangePolicy;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    private const ENGINE_HOURS_AVERAGE_TYPES = [
        'excavator',
        'road roller',
        'loader',
        'bulldozer',
        'backhoe loader',
        'road grader',
        'crane',
        'forklift',
        'paver',
        'tractor',
        'skid steer loader',
    ];

    private const MILEAGE_AVERAGE_TYPE = 'dump truck';

    public function __construct(
        private FleetOwnershipStatsService $ownershipStats,
        private EfficiencyDashboardService $efficiency,
        private DashboardDailyAverageService $dailyAverages,
        private GeofenceViolationService $geofenceViolations,
        private DashboardPerformanceProfiler $performance,
        private DashboardDateRangePolicy $dateRangePolicy,
    ) {}

    public function getOverview(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $query = $this->statsQuery($filters);
        $previous = $this->previousStatsQuery($filters);
        $equipmentCount = $this->equipmentQuery($filters)->count();

        $totalHours = (float) (clone $query)->sum('worked_hours');
        $totalDistance = (float) (clone $query)->sum('distance_km');
        $utilization = (float) (clone $query)->avg('utilization_percent');

        $previousHours = (float) (clone $previous)->sum('worked_hours');
        $previousDistance = (float) (clone $previous)->sum('distance_km');
        $previousUtilization = (float) (clone $previous)->avg('utilization_percent');
        $ownership = (clone $query)
            ->select('ownership_type', DB::raw('SUM(worked_hours) as hours'), DB::raw('SUM(distance_km) as distance'))
            ->groupBy('ownership_type')
            ->orderBy('ownership_type')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->ownership_type,
                'hours' => round((float) $row->hours, 1),
                'distance' => round((float) $row->distance, 1),
            ])
            ->values()
            ->all();
        $ownershipShare = $this->ownershipStats->summary($filters)['rows'];

        return [
            'filters' => $filters,
            'equipment_count' => $equipmentCount,
            'total_hours' => round($totalHours, 1),
            'total_distance' => round($totalDistance, 1),
            'avg_hours_per_equipment' => round($totalHours / max(1, $equipmentCount), 1),
            'avg_distance_per_equipment' => round($totalDistance / max(1, $equipmentCount), 1),
            'utilization' => round($utilization, 1),
            'changes' => [
                'total_hours' => $this->percentChange($totalHours, $previousHours),
                'total_distance' => $this->percentChange($totalDistance, $previousDistance),
                'avg_hours_per_equipment' => $this->percentChange(
                    $totalHours / max(1, $equipmentCount),
                    $previousHours / max(1, $equipmentCount)
                ),
                'avg_distance_per_equipment' => $this->percentChange(
                    $totalDistance / max(1, $equipmentCount),
                    $previousDistance / max(1, $equipmentCount)
                ),
                'utilization' => $this->percentChange($utilization, $previousUtilization),
            ],
            'ownership' => $ownership,
            'ownership_share' => $ownershipShare,
        ];
    }

    public function getWorkHourCategories(array $filters): array
    {
        $rows = $this->statsQuery($this->normalizeFilters($filters))
            ->select('equipment_id', DB::raw('SUM(worked_hours) as hours'))
            ->groupBy('equipment_id')
            ->get();

        $buckets = [
            '0-2 saat' => 0,
            '2-5 saat' => 0,
            '5-8 saat' => 0,
            '8+ saat' => 0,
        ];

        foreach ($rows as $row) {
            $hours = (float) $row->hours;
            if ($hours < 2) {
                $buckets['0-2 saat']++;
            } elseif ($hours < 5) {
                $buckets['2-5 saat']++;
            } elseif ($hours < 8) {
                $buckets['5-8 saat']++;
            } else {
                $buckets['8+ saat']++;
            }
        }

        return [
            'labels' => array_keys($buckets),
            'data' => array_values($buckets),
        ];
    }

    public function getEquipmentTypeDistribution(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        return Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->leftJoin('equipment_daily_stats', function ($join) use ($filters): void {
                $join->on('equipment_daily_stats.equipment_id', '=', 'equipments.id')
                    ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']]);

                if ($filters['project_id']) {
                    $join->where('equipment_daily_stats.project_id', $filters['project_id']);
                }

                if ($filters['ownership_type']) {
                    $join->where('equipment_daily_stats.ownership_type', $filters['ownership_type']);
                }
            })
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->boundToProjectWialonGroup()
            ->operationalDashboardProject()
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('equipments.ownership_type', $ownershipType))
            ->select(
                'equipment_types.name',
                DB::raw('COUNT(DISTINCT equipments.id) as total'),
                DB::raw('COALESCE(SUM(equipment_daily_stats.worked_hours), 0) as hours')
            )
            ->groupBy('equipment_types.id', 'equipment_types.name')
            ->orderByDesc('hours')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'total' => (int) $row->total,
                'hours' => round((float) $row->hours, 1),
            ])
            ->all();
    }

    public function getEquipmentTypeDistributionByOwnership(array $filters): array
    {
        $includeTypeId = (bool) ($filters['_include_type_id'] ?? false);
        $filters = $this->normalizeFilters($filters);
        $result = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        $rows = $this->shareEquipmentQuery($filters)
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->select(
                'equipments.ownership_type',
                'equipment_types.id',
                'equipment_types.name',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('equipments.ownership_type', 'equipment_types.id', 'equipment_types.name')
            ->orderBy('equipments.ownership_type')
            ->orderByDesc('total')
            ->orderBy('equipment_types.name')
            ->get();

        foreach ($rows as $row) {
            if (! array_key_exists($row->ownership_type, $result)) {
                continue;
            }

            $item = [
                'name' => $row->name,
                'total' => (int) $row->total,
            ];

            if ($includeTypeId) {
                $item = ['id' => (int) $row->id] + $item;
            }

            $result[$row->ownership_type][] = $item;
        }

        return $result;
    }

    public function getAverageMetrics(array $filters): array
    {
        $overview = $this->getOverview($filters);

        return [
            'avg_hours_per_equipment' => $overview['avg_hours_per_equipment'],
            'avg_distance_per_equipment' => $overview['avg_distance_per_equipment'],
            'utilization' => $overview['utilization'],
            'changes' => $overview['changes'],
        ];
    }

    public function getAverageMetricsByOwnership(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $equipment = $this->equipmentQuery($filters)->with(['type:id,name'])->get(['id', 'ownership_type', 'equipment_type_id']);
        $localStats = $this->equipmentExportStats($filters);
        $localEngineHoursEquipmentIds = array_keys($localStats['hours']);
        $localMileageEquipmentIds = array_keys($localStats['distance']);

        return $this->buildOwnershipAverageMetrics(
            $equipment,
            $localStats['hours'],
            $localStats['distance'],
            $localEngineHoursEquipmentIds,
            $localMileageEquipmentIds,
            'Local stats'
        );
    }

    public function getProjectDistribution(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $stats = $this->statsQuery($filters)
            ->select(
                'project_id',
                DB::raw('SUM(worked_hours) as hours'),
                DB::raw('AVG(utilization_percent) as utilization')
            )
            ->groupBy('project_id');

        return Project::query()
            ->leftJoinSub($stats, 'stats', fn ($join) => $join->on('stats.project_id', '=', 'projects.id'))
            ->where('projects.active', true)
            ->excludeFromOperationalDashboard()
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('projects.id', $projectId))
            ->select(
                'projects.id',
                'projects.name',
                DB::raw('COALESCE(stats.hours, 0) as hours'),
                DB::raw('COALESCE(stats.utilization, 0) as utilization')
            )
            ->orderByDesc('hours')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'hours' => round((float) $row->hours, 1),
                'utilization' => round((float) $row->utilization, 1),
            ])
            ->all();
    }

    public function getProjectActualHoursByOwnership(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $rows = $this->statsQuery($filters)
            ->join('projects', 'projects.id', '=', 'equipment_daily_stats.project_id')
            ->select(
                'projects.id',
                'projects.name',
                'equipment_daily_stats.ownership_type',
                DB::raw('SUM(equipment_daily_stats.worked_hours) as hours')
            )
            ->groupBy('projects.id', 'projects.name', 'equipment_daily_stats.ownership_type')
            ->get();

        return $this->buildProjectOwnershipRows($rows, 'hours');
    }

    public function getProjectActualWorkHourCategoriesByOwnership(array $filters): array
    {
        return $this->efficiency->projectRowsByOwnership($this->normalizeFilters($filters));
    }

    public function getProjectOwnershipComparison(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $rows = $this->shareEquipmentQuery($filters)
            ->join('projects', 'projects.id', '=', 'equipments.project_id')
            ->select(
                'projects.id',
                'projects.name',
                'equipments.ownership_type',
                DB::raw('COUNT(*) as count_value')
            )
            ->groupBy('projects.id', 'projects.name', 'equipments.ownership_type')
            ->get();

        return $this->buildProjectOwnershipRows($rows, 'count_value');
    }

    public function getActualWorkHourCategories(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $buckets = $this->emptyActualWorkHourBuckets();

        $ownershipTypes = $filters['ownership_type']
            ? [$filters['ownership_type']]
            : [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE];

        foreach ($ownershipTypes as $ownershipType) {
            $summary = $this->efficiency->summaryForOwnership([
                'from' => $filters['from'],
                'to' => $filters['to'],
                'project_id' => $filters['project_id'],
                'project_ids' => $filters['project_ids'] ?? [],
                'equipment_type_id' => $filters['equipment_type_id'],
                'vehicle_types' => $filters['vehicle_types'] ?? [],
            ], $ownershipType);

            foreach (array_keys($buckets[$ownershipType]) as $key) {
                $buckets[$ownershipType][$key] = (int) ($summary[$key] ?? 0);
            }
        }

        return $buckets;
    }

    public function getGeofenceEvents(array $filters)
    {
        $filters = $this->normalizeFilters($filters);

        return GeofenceEvent::query()
            ->with(['equipment', 'project', 'geofence'])
            ->whereHas('equipment', fn (Builder $query) => $query->where('equipments.active', true)->visibleInDashboard()->classifiedForDashboard())
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->whereBetween('exit_at', [
                Carbon::parse($filters['from'])->startOfDay(),
                Carbon::parse($filters['to'])->endOfDay(),
            ])
            ->latest('exit_at')
            ->limit(12)
            ->get();
    }

    public function getGeofenceOutsideRows(array $filters, ?int $limit = 12): array
    {
        $filters = $this->normalizeFilters($filters);
        $fallbackRows = $this->getGeofenceEvents($filters)
            ->map(fn (GeofenceEvent $event): array => [
                'grouping' => $event->equipment?->name ?? '',
                'vendor' => $event->equipment?->ownership_type ?? '',
                'outside_hours' => round(((float) $event->outside_minutes) / 60, 2),
            ])
            ->values()
            ->all();

        return $limit === null ? $fallbackRows : array_slice($fallbackRows, 0, $limit);
    }

    public function getUtilizationTrend(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->statsQuery($filters)
            ->select('stat_date', DB::raw('AVG(utilization_percent) as utilization'))
            ->groupBy('stat_date')
            ->orderBy('stat_date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->stat_date)->toDateString());

        $labels = [];
        $data = [];

        foreach (CarbonPeriod::create($filters['from'], $filters['to']) as $date) {
            $key = $date->toDateString();
            $labels[] = $date->format('d M');
            $data[] = round((float) ($rows[$key]->utilization ?? 0), 1);
        }

        return compact('labels', 'data');
    }

    public function getUtilizationTrendByOwnership(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->statsQuery($filters)
            ->select('stat_date', 'ownership_type', DB::raw('AVG(utilization_percent) as utilization'))
            ->groupBy('stat_date', 'ownership_type')
            ->orderBy('stat_date')
            ->get();

        $byDateAndOwnership = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->stat_date)->toDateString();
            $byDateAndOwnership[$date][$row->ownership_type] = round((float) $row->utilization, 1);
        }

        $labels = [];
        $dates = [];
        $series = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        foreach (CarbonPeriod::create($filters['from'], $filters['to']) as $date) {
            $key = $date->toDateString();
            $dates[] = $key;
            $labels[] = $date->format('d M');
            $series[Equipment::OWNERSHIP_NWC][] = (float) ($byDateAndOwnership[$key][Equipment::OWNERSHIP_NWC] ?? 0.0);
            $series[Equipment::OWNERSHIP_ICARE][] = (float) ($byDateAndOwnership[$key][Equipment::OWNERSHIP_ICARE] ?? 0.0);
        }

        return [
            'labels' => $labels,
            'dates' => $dates,
            'series' => $series,
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    public function getMapData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $events = $this->getGeofenceEvents($filters);

        return [
            'geofences' => Geofence::query()
                ->with('project')
                ->when($filters['project_id'], fn ($query, $projectId) => $query->where('project_id', $projectId))
                ->where('active', true)
                ->get(['id', 'name', 'project_id', 'geometry_json'])
                ->map(fn (Geofence $geofence) => [
                    'id' => $geofence->id,
                    'name' => $geofence->name,
                    'project' => $geofence->project?->name,
                    'geometry' => $geofence->geometry_json,
                ])
                ->values()
                ->all(),
            'equipment' => $events
                ->filter(fn (GeofenceEvent $event): bool => filled($event->equipment?->last_position_json))
                ->unique(fn (GeofenceEvent $event): mixed => $event->equipment_id)
                ->map(fn (GeofenceEvent $event) => [
                    'id' => $event->equipment?->id,
                    'name' => $event->equipment?->name,
                    'type' => $event->equipment?->type?->name,
                    'project' => $event->project?->name,
                    'ownership' => $event->equipment?->ownership_type,
                    'position' => $event->equipment?->last_position_json,
                ])
                ->values()
                ->all(),
        ];
    }

    public function getDashboard(array $filters): array
    {
        $this->performance->begin('dashboard.getDashboard', ['filters' => $filters]);
        $result = null;
        $normalizedFilters = $filters;

        try {
            $filters = $this->performance->measure('normalizeFilters', fn (): array => $this->normalizeFilters($filters));
            $normalizedFilters = $filters;
            $cacheMinutes = max(0, (int) config('fleet.dashboard.cache_minutes', 10));

            if ($cacheMinutes > 0) {
                $result = $this->performance->measure(
                    'cache.lookup',
                    fn (): array => $this->rememberDashboardWithLock($filters, $cacheMinutes)
                );

                return $result;
            }

            $result = $this->performance->measure('buildDashboard', fn (): array => $this->buildDashboard($filters));

            return $result;
        } finally {
            $this->performance->finish('dashboard.getDashboard', ['filters' => $normalizedFilters], $result);
        }
    }

    private function buildDashboard(array $filters): array
    {
        $dashboard = [];

        foreach ($this->dashboardWidgetBuilders($filters) as $key => $builder) {
            $dashboard[$key] = $key === 'averages' && isset($dashboard['overview'])
                ? $this->averageMetricsFromOverview($dashboard['overview'])
                : $this->performance->measure('widget.'.$key, $builder);
        }

        return $dashboard;
    }

    public function getDashboardTab(array $filters, string $tab): array
    {
        $filters = $this->normalizeFilters($filters);
        $tab = array_key_exists($tab, config('dashboard.tabs', []))
            ? $tab
            : (string) config('dashboard.default_tab', 'overview');
        $cacheMinutes = max(0, (int) config('fleet.dashboard.cache_minutes', 10));

        if ($cacheMinutes === 0) {
            return $this->buildDashboardTab($filters, $tab);
        }

        return $this->rememberDashboardTabWithLock($filters, $tab, $cacheMinutes);
    }

    private function buildDashboardTab(array $filters, string $tab): array
    {
        $keys = match ($tab) {
            'efficiency' => [
                'overview',
                'projectActualWorkHourCategoriesByOwnership',
                'dailyAverageDashboards',
            ],
            'geozones' => [
                'overview',
                'geofenceViolations',
            ],
            default => [
                'overview',
                'equipmentTypesByOwnership',
                'projectOwnershipComparison',
                'utilizationTrendByOwnership',
            ],
        };
        $builders = $this->dashboardWidgetBuilders($filters);
        $dashboard = [];

        foreach ($keys as $key) {
            $dashboard[$key] = $this->performance->measure('widget.'.$key, $builders[$key]);
        }

        return $dashboard;
    }

    public function getDashboardProfileWidget(array $filters, string $widget): array
    {
        $this->performance->begin('dashboard.profileWidget', ['filters' => $filters, 'widget' => $widget], force: true);
        $result = null;
        $normalizedFilters = $filters;

        try {
            $filters = $this->performance->measure('normalizeFilters', fn (): array => $this->normalizeFilters($filters));
            $normalizedFilters = $filters;
            $builders = $this->dashboardWidgetBuilders($filters);

            if (! array_key_exists($widget, $builders)) {
                throw new \InvalidArgumentException('Unknown dashboard widget: '.$widget);
            }

            $result = $this->performance->measure('widget.'.$widget, $builders[$widget]);

            return [
                'widget' => $widget,
                'filters' => $filters,
                'result' => $result,
            ];
        } finally {
            $this->performance->finish('dashboard.profileWidget', ['filters' => $normalizedFilters, 'widget' => $widget], $result, forceLog: true);
        }
    }

    public function getDashboardProfile(array $filters): array
    {
        $this->performance->begin('dashboard.profile', ['filters' => $filters], force: true);
        $result = null;
        $normalizedFilters = $filters;

        try {
            $filters = $this->performance->measure('normalizeFilters', fn (): array => $this->normalizeFilters($filters));
            $normalizedFilters = $filters;
            $cacheMinutes = max(0, (int) config('fleet.dashboard.cache_minutes', 10));

            if ($cacheMinutes > 0) {
                $result = $this->performance->measure(
                    'cache.lookup',
                    fn (): array => $this->rememberDashboardWithLock($filters, $cacheMinutes)
                );
            } else {
                $result = $this->performance->measure('buildDashboard', fn (): array => $this->buildDashboard($filters));
            }

            return [
                'filters' => $filters,
                'result' => $result,
            ];
        } finally {
            $this->performance->finish('dashboard.profile', ['filters' => $normalizedFilters], $result, forceLog: true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPerformanceProfile(): array
    {
        return $this->performance->lastProfile();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, callable>
     */
    private function dashboardWidgetBuilders(array $filters): array
    {
        return [
            'overview' => fn (): array => $this->getOverview($filters),
            'workHourCategories' => fn (): array => $this->getWorkHourCategories($filters),
            'equipmentTypes' => fn (): array => $this->getEquipmentTypeDistribution($filters),
            'equipmentTypesByOwnership' => fn (): array => $this->getEquipmentTypeDistributionByOwnership([...$filters, '_include_type_id' => true]),
            'averages' => fn (): array => $this->getAverageMetrics($filters),
            'averageMetricsByOwnership' => fn (): array => $this->getAverageMetricsByOwnership($filters),
            'dailyAverageDashboards' => fn (): array => [
                'engine_hours' => $this->performance->measure(
                    'widget.dailyAverageDashboards.engine_hours',
                    fn (): array => $this->dailyAverages->dashboardData($filters, 'engine_hours')
                ),
                'mileage' => $this->performance->measure(
                    'widget.dailyAverageDashboards.mileage',
                    fn (): array => $this->dailyAverages->dashboardData($filters, 'mileage')
                ),
            ],
            'projects' => fn (): array => $this->getProjectDistribution($filters),
            'projectActualWorkHourCategoriesByOwnership' => fn (): array => $this->getProjectActualWorkHourCategoriesByOwnership($filters),
            'projectOwnershipComparison' => fn (): array => $this->getProjectOwnershipComparison($filters),
            'geofenceViolations' => fn (): array => $this->geofenceViolations->summary($filters),
            'utilizationTrend' => fn (): array => $this->getUtilizationTrend($filters),
            'utilizationTrendByOwnership' => fn (): array => $this->getUtilizationTrendByOwnership($filters),
        ];
    }

    private function dashboardCacheKey(array $filters, string $scope = 'all'): string
    {
        return 'dashboard:aggregate:'.md5(json_encode([
            'version' => 21,
            'data_version' => (int) Cache::get('dashboard:data-version', 1),
            'geofence_versions' => str_contains($scope, 'geozones') ? [
                'violations' => (string) Cache::get('geofence_violations:data_version', 'empty'),
                'transfers' => (string) Cache::get('geofence_transfers:data_version', 'empty'),
            ] : [],
            'scope' => $scope,
            'filters' => $filters,
        ]));
    }

    private function rememberDashboardTabWithLock(array $filters, string $tab, int $cacheMinutes): array
    {
        $cacheKey = $this->dashboardCacheKey($filters, 'tab:'.$tab);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $lockSeconds = max(1, (int) config('fleet.dashboard.cache_lock_seconds', 30));
        $waitSeconds = max(0, (int) config('fleet.dashboard.cache_lock_wait_seconds', 5));

        try {
            return Cache::lock('lock:'.$cacheKey, $lockSeconds)->block(
                $waitSeconds,
                function () use ($cacheKey, $cacheMinutes, $filters, $tab): array {
                    $cached = Cache::get($cacheKey);

                    if (is_array($cached)) {
                        return $cached;
                    }

                    $value = $this->buildDashboardTab($filters, $tab);
                    Cache::put($cacheKey, $value, now()->addMinutes($cacheMinutes));

                    return $value;
                }
            );
        } catch (LockTimeoutException) {
            return Cache::get($cacheKey) ?: $this->buildDashboardTab($filters, $tab);
        }
    }

    private function averageMetricsFromOverview(array $overview): array
    {
        return [
            'avg_hours_per_equipment' => $overview['avg_hours_per_equipment'],
            'avg_distance_per_equipment' => $overview['avg_distance_per_equipment'],
            'utilization' => $overview['utilization'],
            'changes' => $overview['changes'],
        ];
    }

    private function rememberDashboardWithLock(array $filters, int $cacheMinutes): array
    {
        $cacheKey = $this->dashboardCacheKey($filters);
        $cached = $this->performance->measure('cache.get', fn () => Cache::get($cacheKey));

        if (is_array($cached)) {
            return $cached;
        }

        $lockKey = 'lock:'.$cacheKey;
        $lockSeconds = max(1, (int) config('fleet.dashboard.cache_lock_seconds', 30));
        $waitSeconds = max(0, (int) config('fleet.dashboard.cache_lock_wait_seconds', 5));

        try {
            return Cache::lock($lockKey, $lockSeconds)->block($waitSeconds, function () use ($cacheKey, $cacheMinutes, $filters): array {
                $cached = $this->performance->measure('cache.recheck', fn () => Cache::get($cacheKey));

                if (is_array($cached)) {
                    return $cached;
                }

                $value = $this->performance->measure('buildDashboard', fn (): array => $this->buildDashboard($filters));

                Cache::put($cacheKey, $value, now()->addMinutes($cacheMinutes));

                return $value;
            });
        } catch (LockTimeoutException $exception) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }

            Log::warning('Dashboard cache lock timed out; building without cache lock', [
                'cache_key' => $cacheKey,
                'lock_key' => $lockKey,
                'wait_seconds' => $waitSeconds,
            ]);

            return $this->performance->measure('buildDashboard', fn (): array => $this->buildDashboard($filters));
        }
    }

    public function getDashboardExport(array $filters, string $block): array
    {
        abort_if(in_array($block, [
            'daytime_efficiency',
            'daytime-efficiency',
            'nighttime_efficiency',
            'nighttime-efficiency',
            'night_day_efficiency',
            'night-day-efficiency',
            'least-working',
            'most-working',
        ], true), 404);

        if (in_array($block, ['monthly_efficiency', 'monthly-efficiency'], true)) {
            return app(MonthlyEfficiencyDashboardService::class)->export($filters);
        }

        if ($block === 'efficiency') {
            return $this->efficiency->export($filters);
        }

        if ($block === 'geofence-violations-report') {
            $normalized = $this->normalizeFilters($filters, 'export');
            $equipmentType = $normalized['equipment_type_id']
                ? EquipmentType::query()->whereKey($normalized['equipment_type_id'])->value('name')
                : null;

            return app(GeofenceViolationsDashboardService::class)->export([
                'date_from' => $normalized['from'],
                'date_to' => $normalized['to'],
                'project_id' => $normalized['project_id'],
                'equipment_type' => $equipmentType,
                'ownership_type' => $normalized['ownership_type'],
                'status' => null,
                'search' => '',
            ]);
        }

        [$filters, $block] = $this->normalizeExportRequest($filters, $block);

        if (in_array($block, ['actual-work-hours-nwc', 'actual-work-hours-icare'], true)) {
            return $this->efficiency->export($filters);
        }

        $title = $this->dashboardExportTitle($block, $filters);

        if (in_array($block, ['average-engine-hours', 'average-mileage'], true)) {
            $metric = $block === 'average-mileage' ? 'mileage' : 'engine_hours';
            $filterRows = $this->dashboardExportFilters($filters);
            $sections = [
                [
                    'title' => 'Xülasə',
                    'columns' => $this->dailyAverages->summaryColumns($metric),
                    'rows' => $this->dailyAverages->summaryRows($filters, $metric),
                ],
                [
                    'title' => 'Gündəlik detallar',
                    'columns' => array_values($this->dailyAverages->journalColumns($metric)),
                    'rows' => $this->dailyAverages->journalExportRows($filters, $metric),
                ],
            ];

            return [
                'filename' => $this->dashboardExportFilename($title, $filters),
                'title' => $title,
                'filters' => $filterRows,
                'sections' => $sections,
                'sheets' => [
                    [
                        'name' => 'Xülasə',
                        'title' => $title,
                        'filters' => $filterRows,
                        'sections' => [$sections[0]],
                    ],
                    [
                        'name' => 'Gündəlik detallar',
                        'title' => $title,
                        'filters' => $filterRows,
                        'sections' => [$sections[1]],
                    ],
                ],
            ];
        }

        $sections = [
            [
                'title' => __('app.block_data'),
                'columns' => $this->dashboardExportSummaryColumns($block, $filters),
                'rows' => $this->dashboardExportSummaryRows($filters, $block),
            ],
        ];

        $sections[] = [
            'title' => __('app.equipment_details'),
            'columns' => $this->dashboardExportDetailColumns($block),
            'rows' => $this->dashboardExportDetailRows($filters, $block),
        ];

        $filename = match (true) {
            $block === 'geofence-analysis' => $this->geofenceViolationsExportFilename($filters),
            default => $this->dashboardExportFilename($title, $filters),
        };

        return [
            'filename' => $filename,
            'title' => $title,
            'filters' => $this->dashboardExportFilters($filters),
            'sections' => $sections,
        ];
    }

    public function normalizeFilters(array $filters, string $context = 'dashboard'): array
    {
        $range = $this->dateRangePolicy->normalize([
            ...$filters,
            '_default_from' => now(config('app.timezone'))->startOfMonth(),
            '_default_to' => now(config('app.timezone')),
        ], $context);
        $ownershipType = $filters['ownership_type'] ?? null;

        if (! in_array($ownershipType, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $ownershipType = null;
        }

        $projectId = $filters['project_id'] ?? null;
        $projectId = $projectId === '' || $projectId === 'all' ? null : $projectId;

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'project_id' => $projectId !== null ? (int) $projectId : null,
            'equipment_type_id' => isset($filters['equipment_type_id']) && $filters['equipment_type_id'] !== '' ? (int) $filters['equipment_type_id'] : null,
            'ownership_type' => $ownershipType,
        ];
    }

    private function normalizeExportRequest(array $filters, string $block): array
    {
        $block = trim($block) !== '' ? trim($block) : 'overview';

        if ($block === 'equipment-types-nwc') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_NWC;
        }

        if ($block === 'equipment-types-icare') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_ICARE;
        }

        if ($block === 'actual-work-hours-nwc') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_NWC;
        }

        if ($block === 'actual-work-hours-icare') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_ICARE;
        }

        $normalized = $this->normalizeFilters($filters, 'export');
        $normalized['_date_context'] = 'export';

        if (array_key_exists('visible_statuses', $filters)) {
            $normalized['visible_statuses'] = $filters['visible_statuses'];
        }

        return [$normalized, $block];
    }

    private function dashboardExportTitle(string $block, array $filters = []): string
    {
        return match ($block) {
            'work-hours' => __('app.work_hours_by_ownership'),
            'equipment-types-nwc' => __('app.equipment_type_distribution').': '.__('app.ownership_nwc'),
            'equipment-types-icare' => __('app.equipment_type_distribution').': '.__('app.ownership_icare'),
            'equipment-types' => $filters['ownership_type']
                ? __('app.equipment_type_distribution').': '.$this->ownershipLabel($filters['ownership_type'])
                : __('app.equipment_type_distribution'),
            'average-engine-hours' => __('app.avg_engine_hours').': '.__('app.ownership_nwc').' vs '.__('app.ownership_icare'),
            'average-mileage' => __('app.avg_mileage').': '.__('app.ownership_nwc').' vs '.__('app.ownership_icare'),
            'project-averages' => __('app.project_averages').': '.__('app.ownership_nwc').' vs '.__('app.ownership_icare'),
            'ownership-share' => __('app.ownership_share'),
            'geofence-analysis' => __('app.geofence_analysis'),
            'utilization-trend' => __('app.utilization_trend'),
            'actual-work-hours-nwc' => __('app.project_work_hour_categories').': '.__('app.ownership_nwc'),
            'actual-work-hours-icare' => __('app.project_work_hour_categories').': '.__('app.ownership_icare'),
            'actual-work-hour-categories' => __('app.project_work_hour_categories').': '.$this->ownershipLabel($filters['ownership_type'] ?? null),
            'actual-work-hours' => __('app.actual_work_hours_title'),
            'project-comparison' => __('app.work_hours_by_ownership'),
            default => __('app.dashboard'),
        };
    }

    private function dashboardExportFilename(string $title, array $filters): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?: 'dashboard';
        $name = trim($name, '-');

        return strtolower($name).'-'.$filters['from'].'-'.$filters['to'].'.xlsx';
    }

    private function geofenceViolationsExportFilename(array $filters): string
    {
        return $this->geofenceViolations->exportFilename($filters);
    }

    private function dashboardExportFilters(array $filters): array
    {
        $project = $filters['project_id']
            ? Project::query()->find($filters['project_id'])?->name
            : 'Bütün layihələr';
        $type = $filters['equipment_type_id']
            ? EquipmentType::query()->find($filters['equipment_type_id'])?->name
            : 'Bütün növlər';

        return [
            ['Dövr', $filters['from'].' - '.$filters['to']],
            [__('app.project'), $project ?: ''],
            [__('app.equipment_types'), $type ?: ''],
            [__('app.ownership'), $filters['ownership_type'] ? $this->ownershipLabel($filters['ownership_type']) : __('app.ownership_all')],
            [__('app.generated_at'), now()->format('Y-m-d H:i:s')],
        ];
    }

    private function dashboardExportSummaryColumns(string $block, array $filters): array
    {
        return match ($block) {
            'equipment-types-nwc', 'equipment-types-icare' => [__('app.type'), 'Say', 'Faiz'],
            'equipment-types' => [__('app.ownership'), __('app.type'), 'Say'],
            'average-engine-hours', 'average-mileage' => ['Tarix', 'NWC', __('app.ownership_icare'), 'Orta'],
            'project-averages' => ['Göstərici', 'Tip', 'Say', 'Dəyərlər', 'Mənbə'],
            'ownership-share' => [__('app.ownership'), 'Say', 'Faiz'],
            'geofence-analysis' => ['Layihə', 'Texnika sayı'],
            'utilization-trend' => ['Tarix', 'NWC (%)', __('app.ownership_icare').' (%)'],
            'actual-work-hours-nwc', 'actual-work-hours-icare' => [__('app.status'), 'Say'],
            'actual-work-hour-categories' => [
                __('app.project'),
                __('app.worked_less_than_1_hour'),
                __('app.worked_less_than_7_hours'),
                __('app.worked_7_to_10_hours'),
                __('app.worked_night_shift_only'),
                __('app.equipment_without_data'),
                'Cəmi',
                __('app.worked_overtime_hours'),
                __('app.worked_over_10_hours'),
            ],
            'actual-work-hours' => [__('app.project'), 'NWC '.__('app.hours'), __('app.ownership_icare').' '.__('app.hours'), 'Cəmi'],
            'project-comparison' => [__('app.project'), 'NWC', __('app.ownership_icare'), 'Cəmi'],
            default => ['Göstərici', 'Dəyərlər'],
        };
    }

    private function dashboardExportSummaryRows(array $filters, string $block): array
    {
        return match ($block) {
            'work-hours' => collect($this->getOverview($filters)['ownership'])
                ->map(fn (array $row): array => [$this->ownershipLabel($row['label']), $row['hours'].' saat / '.$row['distance'].' km'])
                ->all(),
            'equipment-types-nwc', 'equipment-types-icare' => $this->dashboardExportEquipmentTypeDonutRows($filters),
            'equipment-types' => collect($this->getEquipmentTypeDistributionByOwnership($filters))
                ->flatMap(fn (array $rows, string $ownership): array => collect($rows)
                    ->map(fn (array $row): array => [$this->ownershipLabel($ownership), $row['name'], $row['total']])
                    ->all())
                ->values()
                ->all(),
            'average-engine-hours' => $this->dailyAverages->summaryRows($filters, 'engine_hours'),
            'average-mileage' => $this->dailyAverages->summaryRows($filters, 'mileage'),
            'project-averages' => collect($this->getAverageMetricsByOwnership($filters))
                ->flatMap(fn (array $row): array => [
                    [
                        __('app.avg_engine_hours'),
                        $this->ownershipLabel($row['ownership'] ?? null),
                        $row['engine_hours_equipment_count'],
                        $row['avg_hours'] === null ? __('app.no_data') : $row['avg_hours'].' '.__('app.hours'),
                        $row['source'],
                    ],
                    [
                        __('app.avg_mileage'),
                        $this->ownershipLabel($row['ownership'] ?? null),
                        $row['mileage_equipment_count'],
                        $row['avg_mileage'] === null ? __('app.no_data') : $row['avg_mileage'].' '.__('app.km'),
                        $row['source'],
                    ],
                ])
                ->values()
                ->all(),
            'ownership-share' => collect($this->getOverview($filters)['ownership_share'])
                ->pipe(function ($rows): array {
                    $total = (int) $rows->sum('count');

                    return $rows
                        ->map(fn (array $row): array => [
                            $this->ownershipLabel($row['label']),
                            (int) $row['count'],
                            $this->dashboardExportPercent((int) $row['count'], $total),
                        ])
                        ->push(['Cəmi', $total, $total > 0 ? '100.0%' : '0.0%'])
                        ->all();
                }),
            'geofence-analysis' => collect($this->geofenceViolations->summary($filters)['rows'] ?? [])
                ->values()
                ->map(fn (array $row): array => [
                    $row['label'] ?? $row['project'] ?? '',
                    (int) ($row['count'] ?? 0),
                ])
                ->all(),
            'utilization-trend' => $this->dashboardExportUtilizationTrendRows($filters),
            'actual-work-hours-nwc', 'actual-work-hours-icare' => $this->dashboardExportActualWorkDonutRows($filters),
            'actual-work-hour-categories' => collect($this->getProjectActualWorkHourCategoriesByOwnership($filters)[$filters['ownership_type'] ?? Equipment::OWNERSHIP_NWC] ?? [])
                ->map(fn (array $row): array => [
                    $row['name'],
                    $row['less_than_1_hour'] ?? 0,
                    $row['less_than_7_hours'] ?? 0,
                    $row['between_7_and_10_hours'] ?? 0,
                    $row['night_shift_only'] ?? 0,
                    $row['missing_data'] ?? 0,
                    $row['total'],
                    $row['overtime'] ?? 0,
                    $row['over_10_hours'] ?? 0,
                ])
                ->all(),
            'actual-work-hours' => collect($this->getProjectActualHoursByOwnership($filters))
                ->map(fn (array $row): array => [
                    $row['name'],
                    $row[Equipment::OWNERSHIP_NWC],
                    $row[Equipment::OWNERSHIP_ICARE],
                    $row['total'],
                ])
                ->all(),
            'project-comparison' => collect($this->getProjectOwnershipComparison($filters))
                ->pipe(function ($rows): array {
                    $projectRows = $rows->map(fn (array $row): array => [
                        $row['name'],
                        $row[Equipment::OWNERSHIP_NWC],
                        $row[Equipment::OWNERSHIP_ICARE],
                        $row['total'],
                    ]);
                    $nwcTotal = (float) $rows->sum(Equipment::OWNERSHIP_NWC);
                    $icareTotal = (float) $rows->sum(Equipment::OWNERSHIP_ICARE);

                    return $projectRows
                        ->prepend(['Cəmi', $nwcTotal, $icareTotal, $nwcTotal + $icareTotal])
                        ->all();
                }),
            default => [
                ['Ümumi işləmə saatı', $this->getOverview($filters)['total_hours']],
                ['Ümumi məsafə', $this->getOverview($filters)['total_distance']],
                ['Texnika sayı', $this->getOverview($filters)['equipment_count']],
                ['İstifadə əmsalı', $this->getOverview($filters)['utilization'].'%'],
            ],
        };
    }

    private function dashboardExportDetailColumns(string $block): array
    {
        if ($block === 'geofence-analysis') {
            return array_values($this->geofenceViolations->columns());
        }

        if (in_array($block, ['equipment-types', 'equipment-types-nwc', 'equipment-types-icare'], true)) {
            return [
                '#',
                __('app.equipment_types'),
                'Növ üzrə say',
                'Texnika nömrəsi',
                __('app.project'),
                __('app.ownership'),
            ];
        }

        if (in_array($block, ['actual-work-hours-nwc', 'actual-work-hours-icare'], true)) {
            return [
                '#',
                'Tarix',
                'Texnikanın adı',
                'Qeydiyyat nişanı',
                'Texnika növü',
                __('app.ownership'),
                __('app.project'),
                'Gündüz iş saatı',
                'Overtime saatı',
                'Ümumi iş saatı',
                'Əsas iş statusu',
                'Overtime',
                'Məlumat statusu',
                'Wialon ID',
            ];
        }

        if ($block === 'average-engine-hours') {
            return ['#', 'Tarix', 'Texnikanın adı', 'Texnika növü', __('app.ownership'), __('app.project'), 'Faktiki motosaat', 'Məlumat statusu', 'Wialon ID'];
        }

        if ($block === 'average-mileage') {
            return ['#', 'Tarix', 'Texnikanın adı', 'Texnika növü', __('app.ownership'), __('app.project'), 'Faktiki yürüş, km', 'Məlumat statusu', 'Wialon ID'];
        }

        return [
            '#',
            __('app.project'),
            __('app.ownership'),
            'Wialon qrup ID',
            'Wialon qrup adı',
            __('app.equipment'),
            __('app.equipment_types'),
            'Wialon unit ID',
            'Qeydiyyat nömrəsi',
            __('app.engine_hours'),
            __('app.avg_engine_hours'),
            'İş statusu',
            __('app.mileage').' (km)',
            'Hesablama rejimi',
            'Plan saat/gün',
            'Aktiv',
            'Son sinxron',
            'Enlik',
            'Uzunluq',
            'Sürət',
            'Mənbə',
        ];
    }

    private function dashboardExportDetailRows(array $filters, string $block): array
    {
        if (in_array($block, ['equipment-types', 'equipment-types-nwc', 'equipment-types-icare'], true)) {
            return $this->dashboardEquipmentTypeExportRows($filters, $block);
        }

        if (in_array($block, ['actual-work-hours-nwc', 'actual-work-hours-icare'], true)) {
            return $this->efficiency->exportRows($filters);
        }

        if ($block === 'average-engine-hours') {
            return $this->dailyAverages->journalExportRows($filters, 'engine_hours');
        }

        if ($block === 'average-mileage') {
            return $this->dailyAverages->journalExportRows($filters, 'mileage');
        }

        if ($block === 'geofence-analysis') {
            return $this->geofenceViolations->exportRows($filters);
        }

        return $this->dashboardEquipmentExportRows($filters, $block);
    }

    private function dashboardExportEquipmentTypeDonutRows(array $filters): array
    {
        $ownership = $filters['ownership_type'] ?: Equipment::OWNERSHIP_NWC;
        $rows = collect($this->getEquipmentTypeDistributionByOwnership($filters)[$ownership] ?? []);
        $total = (int) $rows->sum('total');

        return $rows
            ->map(fn (array $row): array => [
                $row['name'],
                (int) $row['total'],
                $this->dashboardExportPercent((int) $row['total'], $total),
            ])
            ->push(['Cəmi', $total, $total > 0 ? '100.0%' : '0.0%'])
            ->all();
    }

    private function dashboardExportActualWorkDonutRows(array $filters): array
    {
        $ownership = $filters['ownership_type'] ?: Equipment::OWNERSHIP_NWC;
        $labels = $this->actualWorkHourDashboardBucketLabels();
        $keys = array_keys($labels);
        $summary = array_fill_keys($keys, 0);
        $total = 0;

        foreach ($this->getProjectActualWorkHourCategoriesByOwnership($filters)[$ownership] ?? [] as $row) {
            foreach ($keys as $key) {
                $summary[$key] += (int) ($row[$key] ?? 0);
            }

            $total += (int) ($row['total'] ?? 0);
        }

        $primaryKeys = ['less_than_1_hour', 'less_than_7_hours', 'between_7_and_10_hours', 'night_shift_only', 'no_data'];
        $additionalKeys = ['overtime', 'over_10_hours'];

        return collect($primaryKeys)
            ->map(fn (string $key): array => [
                $labels[$key],
                $summary[$key],
            ])
            ->push(['Cəmi', $total])
            ->concat(collect($additionalKeys)->map(fn (string $key): array => [
                $labels[$key],
                $summary[$key],
            ]))
            ->all();
    }

    private function dashboardExportPercent(int|float $value, int|float $total): string
    {
        return $total > 0 ? number_format(((float) $value / (float) $total) * 100, 1, '.', '').'%' : '0.0%';
    }

    private function dashboardEquipmentExportRows(array $filters, string $block): array
    {
        $filters = $this->normalizeFilters($filters);
        $localStats = $this->equipmentExportStats($filters);
        $source = 'Local stats';
        $equipmentQuery = in_array($block, ['ownership-share', 'project-comparison'], true)
            ? $this->shareEquipmentQuery($filters)
            : $this->equipmentQuery($filters);
        $equipment = $equipmentQuery
            ->with(['type:id,name', 'project:id,name,code', 'projectWialonGroup:id,name,wialon_group_id'])
            ->get();
        $hoursByEquipmentId = $localStats['hours'];
        $distanceByEquipmentId = $localStats['distance'];
        $statDaysByEquipmentId = $localStats['stat_days'];
        $periodDays = max(1, Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) + 1);

        $groups = ProjectWialonGroup::query()
            ->get(['project_id', 'ownership_type', 'wialon_group_id', 'name'])
            ->keyBy(fn (ProjectWialonGroup $group): string => $group->project_id.'|'.$group->ownership_type);

        $rows = [];

        foreach ($equipment as $item) {
            $hours = (float) ($hoursByEquipmentId[$item->id] ?? 0.0);
            $statDays = (int) ($statDaysByEquipmentId[$item->id] ?? $periodDays);
            $averageDailyHours = $statDays > 0 ? $hours / $statDays : 0.0;
            $group = $item->projectWialonGroup ?? $groups->get(($item->project_id ?? '').'|'.$item->ownership_type);
            $position = $item->last_position_json ?? [];

            $rows[] = [
                'sort_hours' => $hours,
                'sort_name' => $item->name,
                'sort_type' => $item->type?->name ?? '',
                'sort_ownership' => $item->ownership_type,
                'values' => [
                    0,
                    $item->project?->name ?? 'Layihəsiz',
                    $this->ownershipLabel($item->ownership_type),
                    $group?->wialon_group_id ?? $item->matched_wialon_group_id ?? '',
                    $group?->name ?? $item->matched_wialon_group_name ?? '',
                    $item->name,
                    $item->type?->name ?? '',
                    $item->wialon_unit_id,
                    $item->registration_number,
                    round($hours, 1),
                    round($averageDailyHours, 1),
                    $this->actualWorkHourBucketLabel($this->actualWorkHourBucket($averageDailyHours)),
                    round((float) ($distanceByEquipmentId[$item->id] ?? 0.0), 1),
                    $item->calculation_mode,
                    $item->planned_daily_hours,
                    $item->active ? 'Aktiv' : 'Passiv',
                    $item->last_synced_at?->format('Y-m-d H:i:s'),
                    $position['lat'] ?? '',
                    $position['lng'] ?? '',
                    $position['speed'] ?? '',
                    $source,
                ],
            ];
        }

        $rows = $this->sortDashboardEquipmentExportRows($rows, $block);

        return collect($rows)
            ->values()
            ->map(function (array $row, int $index): array {
                $values = $row['values'];
                $values[0] = $index + 1;

                return $values;
            })
            ->all();
    }

    private function dashboardEquipmentTypeExportRows(array $filters, string $block): array
    {
        $filters = $this->normalizeFilters($filters);
        $query = in_array($block, ['equipment-types-nwc', 'equipment-types-icare'], true)
            ? $this->shareEquipmentQuery($filters)
            : $this->equipmentQuery($filters);
        $equipment = $query
            ->with(['type:id,name', 'project:id,name'])
            ->get()
            ->sortBy([
                fn (Equipment $item): string => $item->type?->name ?? '',
                fn (Equipment $item): string => $item->project?->name ?? 'Layihəsiz',
                fn (Equipment $item): string => $item->name,
            ])
            ->values();
        $typeCounts = $equipment
            ->groupBy(fn (Equipment $item): string => $item->type?->name ?? __('app.no_data'))
            ->map(fn ($items): int => $items->count());

        return $equipment
            ->map(function (Equipment $item, int $index) use ($typeCounts): array {
                $typeName = $item->type?->name ?? __('app.no_data');

                return [
                    $index + 1,
                    $typeName,
                    (int) ($typeCounts[$typeName] ?? 0),
                    $item->registration_number ?: $item->name,
                    $item->project?->name ?? 'Layihəsiz',
                    $this->ownershipLabel($item->ownership_type),
                ];
            })
            ->all();
    }

    private function dashboardExportUtilizationTrendRows(array $filters): array
    {
        $trend = $this->getUtilizationTrendByOwnership($filters);

        return collect($trend['labels'])
            ->map(fn (string $label, int $index): array => [
                $label,
                $trend['series'][Equipment::OWNERSHIP_NWC][$index] ?? 0,
                $trend['series'][Equipment::OWNERSHIP_ICARE][$index] ?? 0,
            ])
            ->all();
    }

    private function buildOwnershipAverageMetrics(
        $equipment,
        array $hoursByEquipmentId,
        array $mileageByEquipmentId,
        array $engineHoursEquipmentIds,
        array $mileageEquipmentIds,
        string $source
    ): array {
        $engineHoursEquipmentIdSet = array_fill_keys(array_map('intval', $engineHoursEquipmentIds), true);
        $mileageEquipmentIdSet = array_fill_keys(array_map('intval', $mileageEquipmentIds), true);
        $rows = [
            Equipment::OWNERSHIP_NWC => [
                'ownership' => Equipment::OWNERSHIP_NWC,
                'label' => 'NWC',
                'count' => 0,
                'engine_hours_equipment_count' => 0,
                'mileage_equipment_count' => 0,
                'total_hours' => 0.0,
                'total_mileage' => 0.0,
                'avg_hours' => null,
                'avg_mileage' => null,
                'source' => $source,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'ownership' => Equipment::OWNERSHIP_ICARE,
                'label' => 'İCARƏ',
                'count' => 0,
                'engine_hours_equipment_count' => 0,
                'mileage_equipment_count' => 0,
                'total_hours' => 0.0,
                'total_mileage' => 0.0,
                'avg_hours' => null,
                'avg_mileage' => null,
                'source' => $source,
            ],
        ];

        foreach ($equipment as $item) {
            if (! array_key_exists($item->ownership_type, $rows)) {
                continue;
            }

            $equipmentId = (int) $item->id;
            $type = $this->normalizeEquipmentTypeName($item->type?->name ?? '');
            $rows[$item->ownership_type]['count']++;

            if (isset($engineHoursEquipmentIdSet[$equipmentId]) && in_array($type, self::ENGINE_HOURS_AVERAGE_TYPES, true)) {
                $rows[$item->ownership_type]['engine_hours_equipment_count']++;
                $rows[$item->ownership_type]['total_hours'] += (float) ($hoursByEquipmentId[$equipmentId] ?? 0.0);
            }

            if (isset($mileageEquipmentIdSet[$equipmentId]) && $type === self::MILEAGE_AVERAGE_TYPE) {
                $rows[$item->ownership_type]['mileage_equipment_count']++;
                $rows[$item->ownership_type]['total_mileage'] += (float) ($mileageByEquipmentId[$equipmentId] ?? 0.0);
            }
        }

        foreach ($rows as &$row) {
            $engineCount = (int) $row['engine_hours_equipment_count'];
            $mileageCount = (int) $row['mileage_equipment_count'];
            $row['total_hours'] = round((float) $row['total_hours'], 1);
            $row['total_mileage'] = round((float) $row['total_mileage'], 1);
            $row['avg_hours'] = $engineCount > 0 ? round((float) $row['total_hours'] / $engineCount, 1) : null;
            $row['avg_mileage'] = $mileageCount > 0 ? round((float) $row['total_mileage'] / $mileageCount, 1) : null;
        }
        unset($row);

        return $rows;
    }

    private function normalizeEquipmentTypeName(?string $type): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $type) ?? (string) $type));
    }

    private function emptyProjectOwnershipRow(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            Equipment::OWNERSHIP_NWC => 0.0,
            Equipment::OWNERSHIP_ICARE => 0.0,
            'total' => 0.0,
        ];
    }

    private function emptyProjectActualWorkHourCategoryRow(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'less_than_1_hour' => 0,
            'less_than_7_hours' => 0,
            'between_7_and_10_hours' => 0,
            'over_10_hours' => 0,
            'overtime' => 0,
            'no_data' => 0,
            'total' => 0,
            'missing_data' => 0,
            'overtime_denominator' => 0,
            'overtime_unknown' => 0,
        ];
    }

    private function sortProjectActualWorkHourCategoryRows(array $rows): array
    {
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

    private function buildProjectOwnershipRows($rows, string $valueColumn): array
    {
        $result = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->id;
            $ownershipType = $row->ownership_type;

            if (! in_array($ownershipType, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
                continue;
            }

            $result[$projectId] ??= $this->emptyProjectOwnershipRow($projectId, (string) $row->name);
            $result[$projectId][$ownershipType] += (float) ($row->{$valueColumn} ?? 0.0);
        }

        foreach ($result as &$row) {
            $row[Equipment::OWNERSHIP_NWC] = round((float) $row[Equipment::OWNERSHIP_NWC], 1);
            $row[Equipment::OWNERSHIP_ICARE] = round((float) $row[Equipment::OWNERSHIP_ICARE], 1);
            $row['total'] = round((float) $row[Equipment::OWNERSHIP_NWC] + (float) $row[Equipment::OWNERSHIP_ICARE], 1);
        }
        unset($row);

        return collect($result)
            ->sortBy([
                ['total', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function equipmentExportStats(array $filters): array
    {
        $rows = $this->statsQuery($filters)
            ->select(
                'equipment_id',
                DB::raw('SUM(worked_hours) as hours'),
                DB::raw('SUM(distance_km) as distance'),
                DB::raw('COUNT(DISTINCT stat_date) as stat_days')
            )
            ->groupBy('equipment_id')
            ->get();

        return [
            'hours' => $rows->pluck('hours', 'equipment_id')->map(fn ($value): float => (float) $value)->all(),
            'distance' => $rows->pluck('distance', 'equipment_id')->map(fn ($value): float => (float) $value)->all(),
            'stat_days' => $rows->pluck('stat_days', 'equipment_id')->map(fn ($value): int => (int) $value)->all(),
        ];
    }

    private function sortDashboardEquipmentExportRows(array $rows, string $block): array
    {
        usort($rows, function (array $first, array $second) use ($block): int {
            if (in_array($block, ['equipment-types', 'equipment-types-nwc', 'equipment-types-icare'], true)) {
                return strcmp($first['sort_ownership'], $second['sort_ownership'])
                    ?: strnatcasecmp($first['sort_type'], $second['sort_type'])
                    ?: strnatcasecmp($first['sort_name'], $second['sort_name']);
            }

            if ($block === 'ownership-share') {
                return strcmp($first['sort_ownership'], $second['sort_ownership'])
                    ?: strnatcasecmp($first['sort_name'], $second['sort_name']);
            }

            return strnatcasecmp($first['sort_name'], $second['sort_name']);
        });

        return $rows;
    }

    private function statsQuery(array $filters): Builder
    {
        return $this->applyDailyStatFilters(
            EquipmentDailyStat::query()
                ->whereBetween('stat_date', [$filters['from'], $filters['to']]),
            $filters
        );
    }

    private function previousStatsQuery(array $filters): Builder
    {
        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);
        $days = $from->diffInDays($to) + 1;

        return $this->applyDailyStatFilters(
            EquipmentDailyStat::query()
                ->whereBetween('stat_date', [
                    $from->copy()->subDays($days)->toDateString(),
                    $to->copy()->subDays($days)->toDateString(),
                ]),
            $filters
        );
    }

    private function equipmentQuery(array $filters): Builder
    {
        return Equipment::query()
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->boundToProjectWialonGroup()
            ->operationalDashboardProject()
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('equipments.ownership_type', $ownershipType));
    }

    private function shareEquipmentQuery(array $filters): Builder
    {
        return Equipment::query()
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->boundToProjectWialonGroup()
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('equipments.ownership_type', $ownershipType));
    }

    private function rankedEquipment(array $filters, string $direction, int $limit): array
    {
        $filters = $this->normalizeFilters($filters);

        return Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->join('equipment_daily_stats', 'equipment_daily_stats.equipment_id', '=', 'equipments.id')
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']])
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipment_daily_stats.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('equipment_daily_stats.ownership_type', $ownershipType))
            ->select(
                'equipments.id',
                'equipments.name',
                'equipment_types.name as type_name',
                'equipments.ownership_type',
                DB::raw('SUM(equipment_daily_stats.worked_hours) as hours'),
                DB::raw('SUM(equipment_daily_stats.distance_km) as distance')
            )
            ->groupBy('equipments.id', 'equipments.name', 'equipment_types.name', 'equipments.ownership_type')
            ->orderBy('hours', $direction)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'type' => $row->type_name,
                'ownership' => $row->ownership_type,
                'hours' => round((float) $row->hours, 1),
                'distance' => round((float) $row->distance, 1),
            ])
            ->all();
    }

    private function applyDailyStatFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('ownership_type', $ownershipType))
            ->whereHas('equipment', function (Builder $query) use ($filters): void {
                $query->where('equipments.active', true)
                    ->visibleInDashboard()
                    ->operationalDashboardProject()
                    ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId));
            });
    }

    private function emptyActualWorkHourBuckets(): array
    {
        return [
            Equipment::OWNERSHIP_NWC => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 0,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 0,
            ],
        ];
    }

    private function ownershipLabel(?string $ownershipType): string
    {
        if ($ownershipType === null || $ownershipType === '') {
            return __('app.ownership_all');
        }

        return $ownershipType === Equipment::OWNERSHIP_ICARE
            ? __('app.ownership_icare')
            : __('app.ownership_nwc');
    }

    private function actualWorkHourBucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'less_than_1', 'less_than_1_hour' => __('app.worked_less_than_1_hour'),
            'from_1_to_7', 'less_than_7_hours' => __('app.worked_less_than_7_hours'),
            'from_7_to_10', 'between_7_and_10_hours' => __('app.worked_7_to_10_hours'),
            'night_shift_only' => __('app.worked_night_shift_only'),
            'over_10_hours' => __('app.worked_over_10_hours'),
            'overtime' => __('app.worked_overtime_hours'),
            'no_data' => __('app.no_data'),
            default => $bucket,
        };
    }

    private function actualWorkHourDashboardBucketLabels(): array
    {
        return [
            'less_than_1_hour' => __('app.worked_less_than_1_hour'),
            'less_than_7_hours' => __('app.worked_less_than_7_hours'),
            'between_7_and_10_hours' => __('app.worked_7_to_10_hours'),
            'night_shift_only' => __('app.worked_night_shift_only'),
            'no_data' => __('app.equipment_without_data'),
            'overtime' => __('app.worked_overtime_hours'),
            'over_10_hours' => __('app.worked_over_10_hours'),
        ];
    }

    private function actualWorkHourBucket(float $hours): string
    {
        if ($hours <= 0) {
            return 'no_data';
        }

        if ($hours < 1) {
            return 'less_than_1_hour';
        }

        if ($hours < 7) {
            return 'less_than_7_hours';
        }

        if ($hours <= 10) {
            return 'between_7_and_10_hours';
        }

        return 'over_10_hours';
    }

    private function actualWorkHourBucketFromSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'no_data';
        }

        if ($seconds < 3600) {
            return 'less_than_1_hour';
        }

        if ($seconds < 25200) {
            return 'less_than_7_hours';
        }

        if ($seconds <= 36000) {
            return 'between_7_and_10_hours';
        }

        return 'over_10_hours';
    }

    private function percentChange(float $current, float $previous): float
    {
        if (abs($previous) < 0.01) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
