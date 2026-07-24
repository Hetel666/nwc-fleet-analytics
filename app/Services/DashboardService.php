<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private GeofenceViolationService $geofenceViolations,
        private DashboardDataVersion $dataVersion,
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
        $ownershipShare = $this->equipmentQuery($filters)
            ->select('ownership_type', DB::raw('COUNT(*) as total'))
            ->groupBy('ownership_type')
            ->orderBy('ownership_type')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->ownership_type,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();

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
                    ->where('equipment_daily_stats.stat_date', '>=', $filters['from'])
                    ->where('equipment_daily_stats.stat_date', '<', $this->exclusiveDateTo($filters));

                if ($filters['project_id']) {
                    $join->where('equipment_daily_stats.project_id', $filters['project_id']);
                }

                if ($filters['ownership_type']) {
                    $join->where('equipment_daily_stats.ownership_type', $filters['ownership_type']);
                }
            })
            ->where('equipments.active', true)
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
        $filters = $this->normalizeFilters($filters);
        $result = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        $rows = $this->equipmentQuery($filters)
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->select(
                'equipments.ownership_type',
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

            $result[$row->ownership_type][] = [
                'name' => $row->name,
                'total' => (int) $row->total,
            ];
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
        $equipment = $this->equipmentQuery($filters)->get(['id', 'ownership_type']);
        $localStats = $this->equipmentExportStats($filters);

        return $this->buildOwnershipAverageMetrics(
            $equipment,
            $localStats['hours'],
            $localStats['distance'],
            'Local stats'
        );
    }

    public function getLeastWorking(array $filters, int $limit = 10): array
    {
        return $this->rankedEquipment($filters, 'asc', $limit);
    }

    public function getMostWorking(array $filters, int $limit = 10): array
    {
        return $this->rankedEquipment($filters, 'desc', $limit);
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
        $filters = $this->normalizeFilters($filters);
        $result = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        $rows = $this->equipmentQuery($filters)
            ->join('projects', 'projects.id', '=', 'equipments.project_id')
            ->leftJoin('equipment_daily_stats', function ($join) use ($filters): void {
                $join->on('equipment_daily_stats.equipment_id', '=', 'equipments.id')
                    ->where('equipment_daily_stats.stat_date', '>=', $filters['from'])
                    ->where('equipment_daily_stats.stat_date', '<', $this->exclusiveDateTo($filters));

                if ($filters['project_id']) {
                    $join->where('equipment_daily_stats.project_id', $filters['project_id']);
                }

                if ($filters['ownership_type']) {
                    $join->where('equipment_daily_stats.ownership_type', $filters['ownership_type']);
                }
            })
            ->select(
                'projects.id as project_id',
                'projects.name as project_name',
                'equipments.id as equipment_id',
                'equipments.ownership_type',
                DB::raw('COALESCE(SUM(equipment_daily_stats.worked_hours), 0) as hours'),
                DB::raw('COUNT(DISTINCT equipment_daily_stats.stat_date) as stat_days')
            )
            ->groupBy('projects.id', 'projects.name', 'equipments.id', 'equipments.ownership_type')
            ->get();

        foreach ($rows as $row) {
            $ownershipType = $row->ownership_type;

            if (! array_key_exists($ownershipType, $result)) {
                continue;
            }

            $projectId = (int) $row->project_id;
            $result[$ownershipType][$projectId] ??= $this->emptyProjectActualWorkHourCategoryRow($projectId, (string) $row->project_name);

            $statDays = (int) $row->stat_days;
            if ($statDays === 0) {
                $result[$ownershipType][$projectId]['missing_data']++;

                continue;
            }

            $averageDailyHours = $statDays > 0 ? (float) $row->hours / $statDays : 0.0;
            $bucket = $this->actualWorkHourBucket($averageDailyHours);

            $result[$ownershipType][$projectId][$bucket]++;
            $result[$ownershipType][$projectId]['total']++;
        }

        return $this->sortProjectActualWorkHourCategoryRows($result);
    }

    public function getProjectOwnershipComparison(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $rows = $this->equipmentQuery($filters)
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

        $rows = $this->equipmentQuery($filters)
            ->leftJoin('equipment_daily_stats', function ($join) use ($filters): void {
                $join->on('equipment_daily_stats.equipment_id', '=', 'equipments.id')
                    ->where('equipment_daily_stats.stat_date', '>=', $filters['from'])
                    ->where('equipment_daily_stats.stat_date', '<', $this->exclusiveDateTo($filters));

                if ($filters['project_id']) {
                    $join->where('equipment_daily_stats.project_id', $filters['project_id']);
                }

                if ($filters['ownership_type']) {
                    $join->where('equipment_daily_stats.ownership_type', $filters['ownership_type']);
                }
            })
            ->select(
                'equipments.id',
                'equipments.ownership_type',
                DB::raw('COALESCE(SUM(equipment_daily_stats.worked_hours), 0) as hours'),
                DB::raw('COUNT(DISTINCT equipment_daily_stats.stat_date) as stat_days')
            )
            ->groupBy('equipments.id', 'equipments.ownership_type')
            ->get();

        foreach ($rows as $row) {
            $ownershipType = $row->ownership_type;

            if (! array_key_exists($ownershipType, $buckets)) {
                continue;
            }

            $statDays = (int) $row->stat_days;
            $averageDailyHours = $statDays > 0 ? (float) $row->hours / $statDays : 0.0;
            $buckets[$ownershipType][$this->actualWorkHourBucket($averageDailyHours)]++;
        }

        return $buckets;
    }

    public function getGeofenceEvents(array $filters)
    {
        $filters = $this->normalizeFilters($filters);

        return GeofenceEvent::query()
            ->with(['equipment', 'project', 'geofence'])
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
        $rows = $this->geofenceViolations
            ->currentIntervals($this->normalizeFilters($filters))
            ->map(fn (UnitForeignGeofenceInterval $interval): array => [
                'grouping' => $interval->unit?->name ?? '',
                'vendor' => $interval->ownership_type ?: ($interval->unit?->ownership_type ?? ''),
                'outside_hours' => round($this->geofenceViolations->durationSeconds($interval) / 3600, 2),
                'current_project' => $interval->foreignProject?->name ?? $interval->foreign_project_name ?? '',
                'current_geofence' => $interval->foreignGeofence?->name ?? $interval->foreign_geofence_name ?? '',
            ])
            ->values()
            ->all();

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
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
        $filters = $this->normalizeFilters($filters);
        $cacheMinutes = max(0, (int) config('fleet.dashboard.cache_minutes', 10));

        if ($cacheMinutes > 0) {
            return Cache::remember(
                $this->dashboardCacheKey($filters),
                now()->addMinutes($cacheMinutes),
                fn (): array => $this->buildDashboard($filters)
            );
        }

        return $this->buildDashboard($filters);
    }

    private function buildDashboard(array $filters): array
    {
        return [
            'overview' => $this->getOverview($filters),
            'workHourCategories' => $this->getWorkHourCategories($filters),
            'equipmentTypes' => $this->getEquipmentTypeDistribution($filters),
            'equipmentTypesByOwnership' => $this->getEquipmentTypeDistributionByOwnership($filters),
            'averages' => $this->getAverageMetrics($filters),
            'averageMetricsByOwnership' => $this->getAverageMetricsByOwnership($filters),
            'leastWorking' => $this->getLeastWorking($filters),
            'mostWorking' => $this->getMostWorking($filters),
            'projects' => $this->getProjectDistribution($filters),
            'projectActualWorkHourCategoriesByOwnership' => $this->getProjectActualWorkHourCategoriesByOwnership($filters),
            'projectOwnershipComparison' => $this->getProjectOwnershipComparison($filters),
            'geofenceOutsideRows' => $this->getGeofenceOutsideRows($filters),
            'utilizationTrend' => $this->getUtilizationTrend($filters),
            'utilizationTrendByOwnership' => $this->getUtilizationTrendByOwnership($filters),
            'mapData' => $this->getMapData($filters),
        ];
    }

    private function dashboardCacheKey(array $filters): string
    {
        return 'dashboard:aggregate:'.md5(json_encode([
            'version' => 12,
            'data_version' => $this->dataVersion->current(),
            'filters' => $filters,
        ]));
    }

    public function getDashboardExport(array $filters, string $block): array
    {
        [$filters, $block] = $this->normalizeExportRequest($filters, $block);
        $title = $this->dashboardExportTitle($block, $filters);

        return [
            'filename' => $this->dashboardExportFilename($title, $filters),
            'title' => $title,
            'filters' => $this->dashboardExportFilters($filters),
            'sections' => [
                [
                    'title' => __('app.block_data'),
                    'columns' => $this->dashboardExportSummaryColumns($block),
                    'rows' => $this->dashboardExportSummaryRows($filters, $block),
                ],
                [
                    'title' => __('app.equipment_details'),
                    'columns' => [
                        '#',
                        __('app.project'),
                        __('app.ownership'),
                        'Wialon qrup ID',
                        'Wialon qrup adД±',
                        __('app.equipment'),
                        __('app.equipment_types'),
                        'Wialon unit ID',
                        'Qeydiyyat nГ¶mrЙ™si',
                        __('app.engine_hours'),
                        __('app.avg_engine_hours'),
                        'Д°Еџ statusu',
                        __('app.mileage').' (km)',
                        'Hesablama rejimi',
                        'Plan saat/gГјn',
                        'Aktiv',
                        'Son sinxron',
                        'Enlik',
                        'Uzunluq',
                        'SГјrЙ™t',
                        'MЙ™nbЙ™',
                    ],
                    'rows' => $this->dashboardEquipmentExportRows($filters, $block),
                ],
            ],
        ];
    }

    public function normalizeFilters(array $filters): array
    {
        $from = Carbon::parse($filters['date_from'] ?? $filters['from'] ?? now()->startOfMonth())->toDateString();
        $to = Carbon::parse($filters['date_to'] ?? $filters['to'] ?? now())->toDateString();
        $ownershipType = $filters['ownership_type'] ?? null;

        if (! in_array($ownershipType, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $ownershipType = null;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => isset($filters['project_id']) && $filters['project_id'] !== '' ? (int) $filters['project_id'] : null,
            'equipment_type_id' => isset($filters['equipment_type_id']) && $filters['equipment_type_id'] !== '' ? (int) $filters['equipment_type_id'] : null,
            'ownership_type' => $ownershipType,
        ];
    }

    private function normalizeExportRequest(array $filters, string $block): array
    {
        $block = trim($block) !== '' ? trim($block) : 'overview';

        if ($block === 'equipment-types-nwc') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_NWC;
            $block = 'equipment-types';
        }

        if ($block === 'equipment-types-icare') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_ICARE;
            $block = 'equipment-types';
        }

        if ($block === 'actual-work-hours-nwc') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_NWC;
            $block = 'actual-work-hour-categories';
        }

        if ($block === 'actual-work-hours-icare') {
            $filters['ownership_type'] = Equipment::OWNERSHIP_ICARE;
            $block = 'actual-work-hour-categories';
        }

        return [$this->normalizeFilters($filters), $block];
    }

    private function dashboardExportTitle(string $block, array $filters = []): string
    {
        return match ($block) {
            'work-hours' => __('app.work_hours_by_ownership'),
            'equipment-types' => $filters['ownership_type']
                ? __('app.equipment_type_distribution').': '.$this->ownershipLabel($filters['ownership_type'])
                : __('app.equipment_type_distribution'),
            'project-averages' => __('app.project_averages').': '.__('app.ownership_nwc').' vs '.__('app.ownership_icare'),
            'least-working' => __('app.least_working'),
            'most-working' => __('app.most_working'),
            'ownership-share' => __('app.ownership_share'),
            'geofence-analysis' => __('app.geofence_analysis'),
            'utilization-trend' => __('app.utilization_trend'),
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

    private function dashboardExportFilters(array $filters): array
    {
        $project = $filters['project_id']
            ? Project::query()->find($filters['project_id'])?->name
            : 'BГјtГјn layihЙ™lЙ™r';
        $type = $filters['equipment_type_id']
            ? EquipmentType::query()->find($filters['equipment_type_id'])?->name
            : 'BГјtГјn nГ¶vlЙ™r';

        return [
            ['DГ¶vr', $filters['from'].' - '.$filters['to']],
            [__('app.project'), $project ?: ''],
            [__('app.equipment_types'), $type ?: ''],
            [__('app.ownership'), $filters['ownership_type'] ? $this->ownershipLabel($filters['ownership_type']) : __('app.ownership_all')],
            [__('app.generated_at'), now()->format('Y-m-d H:i:s')],
        ];
    }

    private function dashboardExportSummaryColumns(string $block): array
    {
        return match ($block) {
            'least-working', 'most-working' => ['#', __('app.equipment'), __('app.type'), __('app.ownership'), __('app.hours')],
            'equipment-types' => [__('app.ownership'), __('app.type'), 'Say'],
            'project-averages' => ['Tip', 'Say', __('app.avg_engine_hours'), __('app.avg_mileage'), 'MЙ™nbЙ™'],
            'ownership-share' => [__('app.ownership'), 'Say'],
            'geofence-analysis' => ['#', 'Grouping', 'Vendor', 'outside the geofence hours'],
            'utilization-trend' => ['Tarix', 'NWC (%)', __('app.ownership_icare').' (%)'],
            'actual-work-hour-categories' => array_merge([__('app.project')], array_values($this->actualWorkHourDashboardBucketLabels()), ['CЙ™mi', 'MЙ™lumatД± olmayan texnika']),
            'actual-work-hours' => [__('app.project'), 'NWC '.__('app.hours'), __('app.ownership_icare').' '.__('app.hours'), 'CЙ™mi'],
            'project-comparison' => [__('app.project'), 'NWC', __('app.ownership_icare'), 'CЙ™mi'],
            default => ['GГ¶stЙ™rici', 'DЙ™yЙ™r'],
        };
    }

    private function dashboardExportSummaryRows(array $filters, string $block): array
    {
        return match ($block) {
            'work-hours' => collect($this->getOverview($filters)['ownership'])
                ->map(fn (array $row): array => [$this->ownershipLabel($row['label']), $row['hours'].' saat / '.$row['distance'].' km'])
                ->all(),
            'equipment-types' => collect($this->getEquipmentTypeDistributionByOwnership($filters))
                ->flatMap(fn (array $rows, string $ownership): array => collect($rows)
                    ->map(fn (array $row): array => [$this->ownershipLabel($ownership), $row['name'], $row['total']])
                    ->all())
                ->values()
                ->all(),
            'project-averages' => collect($this->getAverageMetricsByOwnership($filters))
                ->map(fn (array $row): array => [
                    $this->ownershipLabel($row['ownership'] ?? null),
                    $row['count'],
                    $row['avg_hours'],
                    $row['avg_mileage'],
                    $row['source'],
                ])
                ->values()
                ->all(),
            'least-working' => collect($this->getLeastWorking($filters, 10))
                ->values()
                ->map(fn (array $row, int $index): array => [
                    $index + 1,
                    $row['name'],
                    $row['type'],
                    $this->ownershipLabel($row['ownership']),
                    $row['hours'],
                ])
                ->all(),
            'most-working' => collect($this->getMostWorking($filters, 10))
                ->values()
                ->map(fn (array $row, int $index): array => [
                    $index + 1,
                    $row['name'],
                    $row['type'],
                    $this->ownershipLabel($row['ownership']),
                    $row['hours'],
                ])
                ->all(),
            'ownership-share' => collect($this->getOverview($filters)['ownership_share'])
                ->map(fn (array $row): array => [$this->ownershipLabel($row['label']), $row['count']])
                ->all(),
            'geofence-analysis' => collect($this->getGeofenceOutsideRows($filters, null))
                ->values()
                ->map(fn (array $row, int $index): array => [
                    $index + 1,
                    $row['grouping'] ?? '',
                    $row['vendor'] ?? '',
                    $row['outside_hours'] ?? 0,
                ])
                ->all(),
            'utilization-trend' => $this->dashboardExportUtilizationTrendRows($filters),
            'actual-work-hour-categories' => collect($this->getProjectActualWorkHourCategoriesByOwnership($filters)[$filters['ownership_type'] ?? Equipment::OWNERSHIP_NWC] ?? [])
                ->map(fn (array $row): array => [
                    $row['name'],
                    $row['less_than_1'],
                    $row['from_1_to_7'],
                    $row['from_7_to_10'],
                    $row['overtime'],
                    $row['total'],
                    $row['missing_data'] ?? 0,
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
                ->map(fn (array $row): array => [
                    $row['name'],
                    $row[Equipment::OWNERSHIP_NWC],
                    $row[Equipment::OWNERSHIP_ICARE],
                    $row['total'],
                ])
                ->all(),
            default => [
                ['Гњmumi iЕџlЙ™mЙ™ saatД±', $this->getOverview($filters)['total_hours']],
                ['Гњmumi mЙ™safЙ™', $this->getOverview($filters)['total_distance']],
                ['Texnika sayД±', $this->getOverview($filters)['equipment_count']],
                ['Д°stifadЙ™ Й™msalД±', $this->getOverview($filters)['utilization'].'%'],
            ],
        };
    }

    private function dashboardEquipmentExportRows(array $filters, string $block): array
    {
        $filters = $this->normalizeFilters($filters);
        $localStats = $this->equipmentExportStats($filters);
        $equipment = $this->equipmentQuery($filters)
            ->with(['type:id,name', 'project:id,name,code'])
            ->get();
        $hoursByEquipmentId = $localStats['hours'];
        $distanceByEquipmentId = $localStats['distance'];
        $statDaysByEquipmentId = $localStats['stat_days'];
        $periodDays = max(1, Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) + 1);
        $source = 'Local stats';

        $groups = ProjectWialonGroup::query()
            ->get(['project_id', 'ownership_type', 'wialon_group_id', 'name'])
            ->keyBy(fn (ProjectWialonGroup $group): string => $group->project_id.'|'.$group->ownership_type);

        $rows = [];

        foreach ($equipment as $item) {
            $hours = (float) ($hoursByEquipmentId[$item->id] ?? 0.0);
            $statDays = (int) ($statDaysByEquipmentId[$item->id] ?? $periodDays);
            $averageDailyHours = $statDays > 0 ? $hours / $statDays : 0.0;
            $group = $groups->get(($item->project_id ?? '').'|'.$item->ownership_type);
            $position = $item->last_position_json ?? [];

            $rows[] = [
                'sort_hours' => $hours,
                'sort_name' => $item->name,
                'sort_type' => $item->type?->name ?? '',
                'sort_ownership' => $item->ownership_type,
                'values' => [
                    0,
                    $item->project?->name ?? '',
                    $this->ownershipLabel($item->ownership_type),
                    $group?->wialon_group_id ?? '',
                    $group?->name ?? '',
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

        if ($block === 'least-working' || $block === 'most-working') {
            $rows = array_slice($rows, 0, 10);
        }

        return collect($rows)
            ->values()
            ->map(function (array $row, int $index): array {
                $values = $row['values'];
                $values[0] = $index + 1;

                return $values;
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

    private function buildOwnershipAverageMetrics($equipment, array $hoursByEquipmentId, array $mileageByEquipmentId, string $source): array
    {
        $rows = [
            Equipment::OWNERSHIP_NWC => [
                'ownership' => Equipment::OWNERSHIP_NWC,
                'label' => 'NWC',
                'count' => 0,
                'total_hours' => 0.0,
                'total_mileage' => 0.0,
                'avg_hours' => 0.0,
                'avg_mileage' => 0.0,
                'source' => $source,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'ownership' => Equipment::OWNERSHIP_ICARE,
                'label' => 'Д°CARЖЏ',
                'count' => 0,
                'total_hours' => 0.0,
                'total_mileage' => 0.0,
                'avg_hours' => 0.0,
                'avg_mileage' => 0.0,
                'source' => $source,
            ],
        ];

        foreach ($equipment as $item) {
            if (! array_key_exists($item->ownership_type, $rows)) {
                continue;
            }

            $rows[$item->ownership_type]['count']++;
            $rows[$item->ownership_type]['total_hours'] += (float) ($hoursByEquipmentId[$item->id] ?? 0.0);
            $rows[$item->ownership_type]['total_mileage'] += (float) ($mileageByEquipmentId[$item->id] ?? 0.0);
        }

        foreach ($rows as &$row) {
            $count = max(1, (int) $row['count']);
            $row['total_hours'] = round((float) $row['total_hours'], 1);
            $row['total_mileage'] = round((float) $row['total_mileage'], 1);
            $row['avg_hours'] = round((float) $row['total_hours'] / $count, 1);
            $row['avg_mileage'] = round((float) $row['total_mileage'] / $count, 1);
        }
        unset($row);

        return $rows;
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
            'less_than_1' => 0,
            'from_1_to_7' => 0,
            'from_7_to_10' => 0,
            'overtime' => 0,
            'total' => 0,
            'missing_data' => 0,
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
            if ($block === 'least-working') {
                return ($first['sort_hours'] <=> $second['sort_hours'])
                    ?: strnatcasecmp($first['sort_name'], $second['sort_name']);
            }

            if ($block === 'most-working') {
                return ($second['sort_hours'] <=> $first['sort_hours'])
                    ?: strnatcasecmp($first['sort_name'], $second['sort_name']);
            }

            if ($block === 'equipment-types') {
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
                ->where('stat_date', '>=', $filters['from'])
                ->where('stat_date', '<', $this->exclusiveDateTo($filters)),
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
                ->where('stat_date', '>=', $from->copy()->subDays($days)->toDateString())
                ->where('stat_date', '<', $to->copy()->subDays($days)->addDay()->toDateString()),
            $filters
        );
    }

    private function exclusiveDateTo(array $filters): string
    {
        return Carbon::parse($filters['to'])->addDay()->toDateString();
    }

    private function equipmentQuery(array $filters): Builder
    {
        return Equipment::query()
            ->where('equipments.active', true)
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
            ->where('equipment_daily_stats.stat_date', '>=', $filters['from'])
            ->where('equipment_daily_stats.stat_date', '<', $this->exclusiveDateTo($filters))
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
                    ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId));
            });
    }

    private function emptyActualWorkHourBuckets(): array
    {
        return [
            Equipment::OWNERSHIP_NWC => [
                'less_than_1' => 0,
                'from_1_to_7' => 0,
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 0,
                'from_1_to_7' => 0,
                'from_7_to_10' => 0,
                'overtime' => 0,
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
            'less_than_1' => '1 saatdan az',
            'from_1_to_7' => '7 saatdan az',
            'from_7_to_10' => '7-10 saat iЕџlЙ™yЙ™n',
            'overtime' => '10 saatdan Г§ox iЕџlЙ™yЙ™n (Overtime)',
            default => $bucket,
        };
    }

    private function actualWorkHourDashboardBucketLabels(): array
    {
        return [
            'less_than_1' => __('app.worked_less_than_1_hour'),
            'from_1_to_7' => __('app.worked_less_than_7_hours'),
            'from_7_to_10' => __('app.worked_7_to_10_hours'),
            'overtime' => __('app.worked_overtime_hours'),
        ];
    }

    private function actualWorkHourBucket(float $hours): string
    {
        if ($hours < 1) {
            return 'less_than_1';
        }

        if ($hours < 7) {
            return 'from_1_to_7';
        }

        if ($hours <= 10) {
            return 'from_7_to_10';
        }

        return 'overtime';
    }

    private function actualWorkHourBucketFromSeconds(int $seconds): string
    {
        if ($seconds < 3600) {
            return 'less_than_1';
        }

        if ($seconds < 25200) {
            return 'from_1_to_7';
        }

        if ($seconds <= 36000) {
            return 'from_7_to_10';
        }

        return 'overtime';
    }

    private function percentChange(float $current, float $previous): float
    {
        if (abs($previous) < 0.01) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
