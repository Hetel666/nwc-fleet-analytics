<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardService
{
    private array $wialonEngineHoursReportData = [];

    private array $wialonGeofenceOutsideRows = [];

    public function __construct(private WialonService $wialon)
    {
    }

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
                    ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']]);

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
        $reportData = $this->getWialonEngineHoursReportData($filters);

        if ($reportData !== null) {
            return $this->buildOwnershipAverageMetrics(
                $reportData['equipment'],
                $reportData['hours'],
                $reportData['mileage'] ?? [],
                'Wialon report'
            );
        }

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

        if ($filters['project_id']) {
            $reportData = $this->getWialonEngineHoursReportData($filters);

            if ($reportData !== null) {
                $project = Project::query()->find($filters['project_id']);
                $row = $this->emptyProjectOwnershipRow((int) $filters['project_id'], $project?->name ?? '');

                foreach ($reportData['equipment'] as $equipment) {
                    $ownershipType = $equipment->ownership_type;

                    if (! in_array($ownershipType, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
                        continue;
                    }

                    $row[$ownershipType] += (float) ($reportData['hours'][$equipment->id] ?? 0.0);
                }

                $row[Equipment::OWNERSHIP_NWC] = round((float) $row[Equipment::OWNERSHIP_NWC], 1);
                $row[Equipment::OWNERSHIP_ICARE] = round((float) $row[Equipment::OWNERSHIP_ICARE], 1);
                $row['total'] = round((float) $row[Equipment::OWNERSHIP_NWC] + (float) $row[Equipment::OWNERSHIP_ICARE], 1);

                return $row['total'] > 0 ? [$row] : [];
            }
        }

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

        if ($filters['project_id']) {
            $reportData = $this->getWialonEngineHoursReportData($filters);

            if ($reportData !== null) {
                $project = Project::query()->find($filters['project_id']);

                foreach ($reportData['equipment'] as $item) {
                    $ownershipType = $item->ownership_type;

                    if (! array_key_exists($ownershipType, $result)) {
                        continue;
                    }

                    $result[$ownershipType][$filters['project_id']] ??= $this->emptyProjectActualWorkHourCategoryRow(
                        (int) $filters['project_id'],
                        $project?->name ?? ''
                    );

                    $statDays = (int) ($reportData['stat_days'][$item->id] ?? $reportData['period_days']);
                    $averageDailyHours = $statDays > 0 ? ((float) ($reportData['hours'][$item->id] ?? 0.0) / $statDays) : 0.0;
                    $bucket = $this->actualWorkHourBucket($averageDailyHours);

                    $result[$ownershipType][$filters['project_id']][$bucket]++;
                    $result[$ownershipType][$filters['project_id']]['total']++;
                }

                return $this->sortProjectActualWorkHourCategoryRows($result);
            }
        }

        $rows = $this->equipmentQuery($filters)
            ->join('projects', 'projects.id', '=', 'equipments.project_id')
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
        $reportBuckets = $this->getActualWorkHourCategoriesFromWialonReport($filters);

        if ($reportBuckets !== null) {
            return $reportBuckets;
        }

        $buckets = $this->emptyActualWorkHourBuckets();

        $rows = $this->equipmentQuery($filters)
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
        $filters = $this->normalizeFilters($filters);
        $reportRows = $this->getGeofenceOutsideRowsFromWialonReport($filters);

        if ($reportRows !== null) {
            return $limit === null ? $reportRows : array_slice($reportRows, 0, $limit);
        }

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
            'version' => 6,
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
            : 'Bütün layihələr';
        $type = $filters['equipment_type_id']
            ? \App\Models\EquipmentType::query()->find($filters['equipment_type_id'])?->name
            : 'Bütün növlər';

        return [
            ['Dövr', $filters['from'].' - '.$filters['to']],
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
            'project-averages' => ['Tip', 'Say', __('app.avg_engine_hours'), __('app.avg_mileage'), 'Mənbə'],
            'ownership-share' => [__('app.ownership'), 'Say'],
            'geofence-analysis' => ['#', 'Grouping', 'Vendor', 'outside the geofence hours'],
            'utilization-trend' => ['Tarix', 'NWC (%)', __('app.ownership_icare').' (%)'],
            'actual-work-hour-categories' => array_merge([__('app.project')], array_values($this->actualWorkHourDashboardBucketLabels()), ['Cəmi']),
            'actual-work-hours' => [__('app.project'), 'NWC '.__('app.hours'), __('app.ownership_icare').' '.__('app.hours'), 'Cəmi'],
            'project-comparison' => [__('app.project'), 'NWC', __('app.ownership_icare'), 'Cəmi'],
            default => ['Göstərici', 'Dəyər'],
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
                    $row['from_7_to_10'],
                    $row['from_1_to_7'],
                    $row['overtime'],
                    $row['less_than_1'],
                    $row['total'],
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
                ['Ümumi işləmə saatı', $this->getOverview($filters)['total_hours']],
                ['Ümumi məsafə', $this->getOverview($filters)['total_distance']],
                ['Texnika sayı', $this->getOverview($filters)['equipment_count']],
                ['İstifadə əmsalı', $this->getOverview($filters)['utilization'].'%'],
            ],
        };
    }

    private function dashboardEquipmentExportRows(array $filters, string $block): array
    {
        $filters = $this->normalizeFilters($filters);
        $reportData = $this->getWialonEngineHoursReportData($filters);
        $localStats = $this->equipmentExportStats($filters);
        $source = $reportData !== null ? 'Wialon report' : 'Local stats';

        if ($reportData !== null) {
            $equipment = $reportData['equipment'];
            $hoursByEquipmentId = $reportData['hours'];
            $distanceByEquipmentId = $reportData['mileage'] ?? [];
            $statDaysByEquipmentId = $reportData['stat_days'];
            $periodDays = (int) $reportData['period_days'];
        } else {
            $equipment = $this->equipmentQuery($filters)
                ->with(['type:id,name', 'project:id,name,code'])
                ->get();
            $hoursByEquipmentId = $localStats['hours'];
            $distanceByEquipmentId = $localStats['distance'];
            $statDaysByEquipmentId = $localStats['stat_days'];
            $periodDays = max(1, Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) + 1);
        }

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
                'label' => 'İCARƏ',
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
            EquipmentDailyStat::query()->whereBetween('stat_date', [$filters['from'], $filters['to']]),
            $filters
        );
    }

    private function previousStatsQuery(array $filters): Builder
    {
        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);
        $days = $from->diffInDays($to) + 1;

        return $this->applyDailyStatFilters(
            EquipmentDailyStat::query()->whereBetween('stat_date', [
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
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('equipments.ownership_type', $ownershipType));
    }

    private function rankedEquipment(array $filters, string $direction, int $limit): array
    {
        $filters = $this->normalizeFilters($filters);
        $reportRows = $this->rankedEquipmentFromWialonReport($filters, $direction, $limit);

        if ($reportRows !== null) {
            return $reportRows;
        }

        return Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->join('equipment_daily_stats', 'equipment_daily_stats.equipment_id', '=', 'equipments.id')
            ->where('equipments.active', true)
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

    private function rankedEquipmentFromWialonReport(array $filters, string $direction, int $limit): ?array
    {
        $reportData = $this->getWialonEngineHoursReportData($filters);

        if ($reportData === null) {
            return null;
        }

        $rows = [];

        foreach ($reportData['equipment'] as $equipment) {
            $rows[] = [
                'id' => (int) $equipment->id,
                'name' => $equipment->name,
                'type' => $equipment->type?->name,
                'ownership' => $equipment->ownership_type,
                'hours' => round((float) ($reportData['hours'][$equipment->id] ?? 0.0), 1),
                'distance' => round((float) ($reportData['mileage'][$equipment->id] ?? 0.0), 1),
            ];
        }

        usort($rows, function (array $first, array $second) use ($direction): int {
            $hoursCompare = $first['hours'] <=> $second['hours'];

            if ($hoursCompare !== 0) {
                return $direction === 'asc' ? $hoursCompare : -$hoursCompare;
            }

            return strnatcasecmp($first['name'], $second['name']);
        });

        return array_slice($rows, 0, $limit);
    }

    private function getGeofenceOutsideRowsFromWialonReport(array $filters): ?array
    {
        if (! $filters['project_id']) {
            return null;
        }

        $settings = $this->wialonGeofenceOutsideReportSettings();

        if ($settings === null) {
            return null;
        }

        $groups = ProjectWialonGroup::query()
            ->where('project_id', $filters['project_id'])
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('ownership_type', $ownershipType))
            ->get()
            ->keyBy('ownership_type');

        if ($groups->isEmpty()) {
            return null;
        }

        $equipment = $this->equipmentQuery($filters)->get(['id', 'name', 'ownership_type']);

        if ($equipment->isEmpty()) {
            return [];
        }

        $equipmentIdByReportKey = [];
        $equipmentNameByReportKey = [];

        foreach ($equipment as $item) {
            $key = $this->reportUnitKey($item->ownership_type, $item->name);
            $equipmentIdByReportKey[$key] = (int) $item->id;
            $equipmentNameByReportKey[$key] = $item->name;
        }

        $cacheKey = $this->wialonGeofenceOutsideReportCacheKey(
            $filters,
            $settings,
            $groups->pluck('wialon_group_id', 'ownership_type')->all()
        );
        $cachedRows = Cache::get($cacheKey);

        if (is_array($cachedRows)) {
            return $cachedRows;
        }

        $periodStart = Carbon::parse($filters['from'], config('app.timezone'))->startOfDay();
        $periodEnd = Carbon::parse($filters['to'], config('app.timezone'))->endOfDay();
        $rowsByEquipment = [];
        $successfulReports = 0;
        $failedReports = 0;

        foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownershipType) {
            $group = $groups->get($ownershipType);

            if (! $group) {
                continue;
            }

            try {
                $report = $this->wialon->getReportTablesRows(
                    $settings['resource_id'],
                    $settings['template_id'],
                    $group->wialon_group_id,
                    $periodStart->timestamp,
                    $periodEnd->timestamp,
                    500,
                    16777216,
                    false,
                    max(30, (int) config('fleet.wialon.geofence_outside_report_timeout', 120))
                );
                $successfulReports++;
            } catch (Throwable $exception) {
                $failedReports++;
                Log::warning('Wialon geofence outside report failed', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            $engineTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'engine');
            $geofenceTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'geofence');

            if ($engineTable === null || $geofenceTable === null) {
                Log::warning('Wialon geofence outside report tables missing', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'tables' => collect($report['tables'] ?? [])->map(fn (array $item): string => (string) (($item['table']['label'] ?? null) ?: ($item['table']['name'] ?? '')))->all(),
                ]);
                continue;
            }

            $engineRows = $this->engineRowsByReportKey($engineTable, $ownershipType, $equipmentIdByReportKey);
            $geofenceHours = $this->geofenceHoursByReportKey($geofenceTable, $ownershipType, $equipmentIdByReportKey);

            foreach ($engineRows as $rowKey => $engineRow) {
                $equipmentKey = $engineRow['equipment_key'];
                $date = $engineRow['date'];
                $insideHours = $date === ''
                    ? (float) ($geofenceHours['unit'][$equipmentKey] ?? 0.0)
                    : (float) ($geofenceHours['date_unit'][$rowKey] ?? ($geofenceHours['has_dated_rows'] ? 0.0 : ($geofenceHours['unit'][$equipmentKey] ?? 0.0)));
                $outsideHours = round(max((float) $engineRow['engine_hours'] - $insideHours, 0.0), 2);

                $rowsByEquipment[$equipmentKey] ??= [
                    'grouping' => $equipmentNameByReportKey[$equipmentKey] ?? $engineRow['grouping'],
                    'vendor' => $engineRow['vendor'] ?: $this->ownershipLabel($ownershipType),
                    'outside_hours' => 0.0,
                ];
                $rowsByEquipment[$equipmentKey]['outside_hours'] += $outsideHours;
            }
        }

        if ($successfulReports === 0) {
            return null;
        }

        if ($rowsByEquipment === [] && $failedReports > 0) {
            return null;
        }

        $rows = collect($rowsByEquipment)
            ->map(fn (array $row): array => [
                'grouping' => $row['grouping'],
                'vendor' => $row['vendor'],
                'outside_hours' => round((float) $row['outside_hours'], 2),
            ])
            ->sortBy([
                ['outside_hours', 'desc'],
                ['grouping', 'asc'],
            ])
            ->values()
            ->all();

        if ($failedReports === 0) {
            Cache::put(
                $cacheKey,
                $rows,
                now()->addMinutes(max(1, (int) config('fleet.wialon.geofence_outside_report_cache_minutes', 30)))
            );
        }

        return $rows;
    }

    private function engineRowsByReportKey(array $reportTable, string $ownershipType, array $equipmentIdByReportKey): array
    {
        $table = $reportTable['table'] ?? [];
        $headers = $this->reportHeaders($table);
        $engineIndex = $this->engineHoursColumnIndex($table);
        $records = [];

        $this->collectEngineReportRecords($reportTable['rows'] ?? [], $headers, $engineIndex, $records);

        $rows = [];

        foreach ($records as $record) {
            $grouping = $record['_grouping'] ?? $this->reportRecordValue($record, ['Grouping', 'Texnika', 'Техника']);

            if (! $this->isReportGrouping($grouping)) {
                continue;
            }

            $equipmentKey = $this->reportUnitKey($ownershipType, (string) $grouping);

            if (! isset($equipmentIdByReportKey[$equipmentKey])) {
                continue;
            }

            $cells = $record['_cells'] ?? [];
            $engineHours = $this->parseReportHours(
                $this->reportRecordValue($record, ['Engine hours'])
                    ?? ($cells[$engineIndex] ?? null)
            );
            $date = (string) ($record['_date'] ?? '');
            $rowKey = $date.'|'.$equipmentKey;

            $rows[$rowKey] ??= [
                'equipment_key' => $equipmentKey,
                'grouping' => (string) $grouping,
                'vendor' => (string) ($this->reportRecordValue($record, ['Vendor', 'Mülkiyyət', 'Mulkiyyet']) ?? ''),
                'date' => $date,
                'engine_hours' => 0.0,
            ];
            $rows[$rowKey]['engine_hours'] += $engineHours;
        }

        return $rows;
    }

    private function geofenceHoursByReportKey(array $reportTable, string $ownershipType, array $equipmentIdByReportKey): array
    {
        $headers = $this->reportHeaders($reportTable['table'] ?? []);
        $records = [];

        $this->collectGeofenceReportRecords($reportTable['rows'] ?? [], $headers, $records);

        $dateUnitHours = [];
        $unitHours = [];
        $hasDatedRows = false;

        foreach ($records as $record) {
            $grouping = $record['_grouping'] ?? $this->reportRecordValue($record, ['Grouping', 'Texnika', 'Техника']);

            if (! $this->isReportGrouping($grouping)) {
                continue;
            }

            $equipmentKey = $this->reportUnitKey($ownershipType, (string) $grouping);

            if (! isset($equipmentIdByReportKey[$equipmentKey])) {
                continue;
            }

            $date = (string) ($record['_date'] ?? '');
            $hours = $this->geofenceDurationHours($record);

            if ($hours <= 0) {
                continue;
            }

            if ($date !== '') {
                $hasDatedRows = true;
            }

            $dateUnitHours[$date.'|'.$equipmentKey] = ($dateUnitHours[$date.'|'.$equipmentKey] ?? 0.0) + $hours;
            $unitHours[$equipmentKey] = ($unitHours[$equipmentKey] ?? 0.0) + $hours;
        }

        return [
            'date_unit' => $dateUnitHours,
            'unit' => $unitHours,
            'has_dated_rows' => $hasDatedRows,
        ];
    }

    private function collectEngineReportRecords(
        array $rows,
        array $headers,
        int $engineIndex,
        array &$records,
        ?string $currentDate = null,
        ?string $currentUnit = null
    ): void {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = $this->reportRowCells($row);
            $firstCell = $cells[0] ?? '';
            $rowDate = $this->parseReportDateText($firstCell);
            $children = is_array($row['r'] ?? null) ? $row['r'] : [];

            if ($rowDate !== null) {
                if ($currentUnit !== null && $this->reportCellHasValue($cells[$engineIndex] ?? null)) {
                    $record = $this->reportRecordFromCells($headers, $cells);
                    $record['_date'] = $rowDate;
                    $record['_grouping'] = $currentUnit;
                    $records[] = $record;
                }

                $this->collectEngineReportRecords($children, $headers, $engineIndex, $records, $rowDate, $currentUnit);
                continue;
            }

            if ($children !== []) {
                $unit = $this->isReportGrouping($firstCell) ? (string) $firstCell : $currentUnit;
                $this->collectEngineReportRecords($children, $headers, $engineIndex, $records, $currentDate, $unit);
                continue;
            }

            $record = $this->reportRecordFromCells($headers, $cells);
            $grouping = $this->reportRecordValue($record, ['Grouping', 'Texnika', 'Техника']) ?: $currentUnit ?: $firstCell;

            if (! $this->isReportGrouping($grouping)) {
                continue;
            }

            $record['_date'] = $this->reportRecordDate($record) ?? $currentDate ?? '';
            $record['_grouping'] = (string) $grouping;
            $records[] = $record;
        }
    }

    private function collectGeofenceReportRecords(
        array $rows,
        array $headers,
        array &$records,
        ?string $currentDate = null,
        ?string $currentUnit = null
    ): void {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = $this->reportRowCells($row);
            $firstCell = $cells[0] ?? '';
            $rowDate = $this->parseReportDateText($firstCell);
            $children = is_array($row['r'] ?? null) ? $row['r'] : [];

            if ($rowDate !== null) {
                if ($children === []) {
                    $record = $this->reportRecordFromCells($headers, $cells);
                    $record['_date'] = $rowDate;
                    $records[] = $record;
                } else {
                    $this->collectGeofenceReportRecords($children, $headers, $records, $rowDate, $currentUnit);
                }
                continue;
            }

            if ($children !== []) {
                $unit = $this->isReportGrouping($firstCell) ? (string) $firstCell : $currentUnit;
                $this->collectGeofenceReportRecords($children, $headers, $records, $currentDate, $unit);
                continue;
            }

            $convertedCells = $cells;

            if ($currentUnit !== null && isset($cells[1])) {
                $convertedCells = [$currentUnit, $cells[1], ...array_slice($cells, 2)];
            }

            $record = $this->reportRecordFromCells($headers, $convertedCells);
            $grouping = $this->reportRecordValue($record, ['Grouping', 'Texnika', 'Техника']) ?: $currentUnit ?: $firstCell;

            if (! $this->isReportGrouping($grouping)) {
                continue;
            }

            $record['_date'] = $this->reportRecordDate($record) ?? $currentDate ?? '';
            $record['_grouping'] = (string) $grouping;
            $records[] = $record;
        }
    }

    private function wialonReportTableByKind(array $tables, string $kind): ?array
    {
        foreach ($tables as $table) {
            $meta = $table['table'] ?? [];
            $text = mb_strtolower(trim(implode(' ', array_filter([
                $meta['label'] ?? '',
                $meta['name'] ?? '',
                ...($meta['header'] ?? []),
            ]))));

            if ($kind === 'engine' && str_contains($text, 'engine') && ! str_contains($text, 'geofence')) {
                return $table;
            }

            if ($kind === 'geofence' && (
                str_contains($text, 'geofence')
                || str_contains($text, 'geozone')
                || str_contains($text, 'zone')
                || str_contains($text, 'геозон')
            )) {
                return $table;
            }
        }

        return $kind === 'engine'
            ? ($tables[0] ?? null)
            : ($tables[1] ?? null);
    }

    private function reportHeaders(array $table): array
    {
        return array_map(fn ($header): string => $this->cleanReportHeader((string) $header), $table['header'] ?? []);
    }

    private function reportRowCells(array $row): array
    {
        return array_map(fn ($cell): string => $this->reportCellText($cell), $row['c'] ?? []);
    }

    private function reportRecordFromCells(array $headers, array $cells): array
    {
        $record = ['_cells' => $cells];

        foreach ($cells as $index => $cell) {
            $header = $headers[$index] ?? 'Column '.($index + 1);
            $record[$header] = $cell;
        }

        return $record;
    }

    private function reportRecordValue(array $record, array $names): mixed
    {
        $normalized = [];

        foreach ($record as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            $normalized[$this->cleanReportHeaderKey((string) $key)] = $value;
        }

        foreach ($names as $name) {
            $key = $this->cleanReportHeaderKey($name);

            if (array_key_exists($key, $normalized) && $this->reportCellHasValue($normalized[$key])) {
                return $normalized[$key];
            }
        }

        return null;
    }

    private function geofenceDurationHours(array $record): float
    {
        $value = $this->reportRecordValue($record, [
            'Duration of stay',
            'Duration',
            'Dəq.',
            'Saat',
            'SAAT',
            'время часы',
            'Длительность нахождения',
        ]);

        if ($value !== null) {
            return $this->parseReportHours($value);
        }

        foreach ($record as $header => $candidate) {
            if (str_starts_with((string) $header, '_') || ! $this->reportCellHasValue($candidate)) {
                continue;
            }

            $key = $this->cleanReportHeaderKey((string) $header);

            if (
                (str_contains($key, 'duration') || str_contains($key, 'saat') || str_contains($key, 'hours') || str_contains($key, 'время часы'))
                && ! str_contains($key, 'engine')
                && ! str_contains($key, 'entry')
                && ! str_contains($key, 'exit')
                && ! str_contains($key, 'begin')
                && ! str_contains($key, 'end')
            ) {
                return $this->parseReportHours($candidate);
            }
        }

        return 0.0;
    }

    private function reportRecordDate(array $record): ?string
    {
        $value = $this->reportRecordValue($record, ['Tarix', 'Date', 'Дата']);

        return $value !== null ? $this->parseReportDateText((string) $value) : null;
    }

    private function parseReportDateText(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd.m.y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text, config('app.timezone'));

                if ($date !== false) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next date format.
            }
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{2}\.\d{2}\.\d{4})\b/', $text, $matches)) {
            try {
                return Carbon::createFromFormat('d.m.Y', $matches[1], config('app.timezone'))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function reportCellHasValue(mixed $value): bool
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        $text = trim((string) $value);

        return $text !== '' && ! in_array($text, ['-', '-----', 'Total'], true);
    }

    private function isReportGrouping(mixed $value): bool
    {
        if (! $this->reportCellHasValue($value)) {
            return false;
        }

        $text = trim((string) $value);

        return $this->parseReportDateText($text) === null && mb_strtolower($text) !== 'total';
    }

    private function cleanReportHeader(string $header): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\n", ' ', $header)) ?? $header);
    }

    private function cleanReportHeaderKey(string $header): string
    {
        return mb_strtolower($this->cleanReportHeader($header));
    }

    private function wialonGeofenceOutsideReportSettings(): ?array
    {
        $resourceId = (int) config('fleet.wialon.geofence_outside_report_resource_id');
        $templateId = (int) config('fleet.wialon.geofence_outside_report_template_id');

        if ($resourceId <= 0) {
            return null;
        }

        if ($templateId <= 0) {
            $templateName = (string) config('fleet.wialon.geofence_outside_report_template_name', '');

            if ($templateName === '') {
                return null;
            }

            $templateId = (int) Cache::remember(
                'dashboard:wialon-geofence-outside-template:'.md5($resourceId.'|'.$templateName),
                now()->addHours(6),
                fn (): int => (int) ($this->wialon->findReportTemplateIdByName($resourceId, $templateName) ?? 0)
            );
        }

        if ($templateId <= 0) {
            return null;
        }

        return [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
        ];
    }

    private function wialonGeofenceOutsideReportCacheKey(array $filters, array $settings, array $groupIds): string
    {
        ksort($groupIds);

        return 'dashboard:wialon-geofence-outside:'.md5(json_encode([
            'version' => 1,
            'filters' => $filters,
            'settings' => $settings,
            'groups' => $groupIds,
        ]));
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

    private function getActualWorkHourCategoriesFromWialonReport(array $filters): ?array
    {
        $reportData = $this->getWialonEngineHoursReportData($filters);

        if ($reportData === null) {
            return null;
        }

        $buckets = $this->emptyActualWorkHourBuckets();

        foreach ($reportData['equipment'] as $item) {
            if (! array_key_exists($item->ownership_type, $buckets)) {
                continue;
            }

            $statDays = (int) ($reportData['stat_days'][$item->id] ?? $reportData['period_days']);
            $averageDailyHours = $statDays > 0 ? ((float) ($reportData['hours'][$item->id] ?? 0.0) / $statDays) : 0.0;
            $buckets[$item->ownership_type][$this->actualWorkHourBucket($averageDailyHours)]++;
        }

        return $buckets;
    }

    private function getWialonEngineHoursReportData(array $filters): ?array
    {
        $filters = $this->normalizeFilters($filters);

        if (! $filters['project_id']) {
            return null;
        }

        $cacheKey = md5(json_encode($filters));

        if (array_key_exists($cacheKey, $this->wialonEngineHoursReportData)) {
            return $this->wialonEngineHoursReportData[$cacheKey];
        }

        $groups = ProjectWialonGroup::query()
            ->where('project_id', $filters['project_id'])
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('ownership_type', $ownershipType))
            ->get()
            ->keyBy('ownership_type');

        if ($groups->isEmpty()) {
            return $this->wialonEngineHoursReportData[$cacheKey] = null;
        }

        $equipment = $this->equipmentQuery($filters)
            ->with(['type:id,name', 'project:id,name,code'])
            ->get();

        $periodStart = Carbon::parse($filters['from'], config('app.timezone'))->startOfDay();
        $periodEnd = Carbon::parse($filters['to'], config('app.timezone'))->endOfDay();
        $periodDays = max(1, (int) $periodStart->diffInDays($periodEnd) + 1);

        if ($equipment->isEmpty()) {
            return $this->wialonEngineHoursReportData[$cacheKey] = [
                'equipment' => $equipment,
                'hours' => [],
                'mileage' => [],
                'stat_days' => [],
                'period_days' => $periodDays,
                'source' => 'empty',
            ];
        }

        $hoursByEquipmentId = [];
        $mileageByEquipmentId = [];
        $equipmentIdByReportKey = [];

        foreach ($equipment as $item) {
            $hoursByEquipmentId[$item->id] = 0.0;
            $mileageByEquipmentId[$item->id] = 0.0;
            $equipmentIdByReportKey[$this->reportUnitKey($item->ownership_type, $item->name)] = $item->id;
        }

        $settings = $this->wialonReportSettings();
        $persistentCacheKey = $this->wialonEngineHoursReportCacheKey(
            $filters,
            $settings,
            $groups->pluck('wialon_group_id', 'ownership_type')->all()
        );
        $cachedReportData = Cache::get($persistentCacheKey);

        if (is_array($cachedReportData) && isset($cachedReportData['hours'], $cachedReportData['mileage'], $cachedReportData['stat_days'])) {
            return $this->wialonEngineHoursReportData[$cacheKey] = [
                'equipment' => $equipment,
                'hours' => $cachedReportData['hours'],
                'mileage' => $cachedReportData['mileage'],
                'stat_days' => $cachedReportData['stat_days'],
                'period_days' => $periodDays,
                'source' => $cachedReportData['source'] ?? 'cache',
            ];
        }

        $intervalData = $this->getIntervalWialonEngineHours(
            $filters,
            $groups,
            $settings,
            $periodStart->timestamp,
            $periodEnd->timestamp,
            $periodDays,
            $hoursByEquipmentId,
            $mileageByEquipmentId,
            $equipmentIdByReportKey
        );

        if ($intervalData !== null) {
            $failedOwnershipTypes = $intervalData['failed_ownership_types'] ?? [];
            unset($intervalData['failed_ownership_types']);

            if ($failedOwnershipTypes === []) {
                $this->cacheWialonEngineHoursReportData($persistentCacheKey, $intervalData, 'interval');

                return $this->wialonEngineHoursReportData[$cacheKey] = [
                    'equipment' => $equipment,
                    ...$intervalData,
                    'period_days' => $periodDays,
                    'source' => 'interval',
                ];
            }

            $dailyReportCount = count($failedOwnershipTypes) * $periodDays;
            $dailyFallbackMaxReports = max(0, (int) config('fleet.wialon.daily_report_fallback_max_reports', 14));

            if ($dailyReportCount <= $dailyFallbackMaxReports) {
                $dailyData = $this->getDailyWialonEngineHours(
                    $filters,
                    $groups,
                    $settings,
                    $intervalData['hours'],
                    $intervalData['mileage'],
                    $equipmentIdByReportKey,
                    $failedOwnershipTypes
                );

                if ($dailyData !== null) {
                    $mergedData = [
                        'hours' => $dailyData['hours'],
                        'mileage' => $dailyData['mileage'],
                        'stat_days' => $dailyData['stat_days'] + $intervalData['stat_days'],
                    ];

                    $this->cacheWialonEngineHoursReportData($persistentCacheKey, $mergedData, 'interval_daily');

                    return $this->wialonEngineHoursReportData[$cacheKey] = [
                        'equipment' => $equipment,
                        ...$mergedData,
                        'period_days' => $periodDays,
                        'source' => 'interval_daily',
                    ];
                }
            }

            Log::info('Skipping partial Wialon daily engine hours fallback', [
                'project_id' => $filters['project_id'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'failed_ownership_types' => $failedOwnershipTypes,
                'daily_report_count' => $dailyReportCount,
                'max_reports' => $dailyFallbackMaxReports,
            ]);

            return $this->wialonEngineHoursReportData[$cacheKey] = [
                'equipment' => $equipment,
                ...$intervalData,
                'period_days' => $periodDays,
                'source' => 'interval_partial',
            ];
        }

        $dailyFallbackMaxDays = max(0, (int) config('fleet.wialon.daily_report_fallback_max_days', 3));

        if ($periodDays > $dailyFallbackMaxDays) {
            Log::info('Skipping Wialon daily engine hours fallback for long date range', [
                'project_id' => $filters['project_id'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'period_days' => $periodDays,
                'max_days' => $dailyFallbackMaxDays,
            ]);

            return $this->wialonEngineHoursReportData[$cacheKey] = null;
        }

        $dailyData = $this->getDailyWialonEngineHours($filters, $groups, $settings, $hoursByEquipmentId, $mileageByEquipmentId, $equipmentIdByReportKey);

        if ($dailyData !== null) {
            $this->cacheWialonEngineHoursReportData($persistentCacheKey, $dailyData, 'daily');

            return $this->wialonEngineHoursReportData[$cacheKey] = [
                'equipment' => $equipment,
                ...$dailyData,
                'period_days' => $periodDays,
                'source' => 'daily',
            ];
        }

        return $this->wialonEngineHoursReportData[$cacheKey] = null;
    }

    private function wialonEngineHoursReportCacheKey(array $filters, array $settings, array $groupIds): string
    {
        ksort($groupIds);

        return 'dashboard:wialon-engine-hours:'.md5(json_encode([
            'version' => 2,
            'filters' => $filters,
            'settings' => $settings,
            'groups' => $groupIds,
        ]));
    }

    private function cacheWialonEngineHoursReportData(string $cacheKey, array $reportData, string $source): void
    {
        $ttlMinutes = max(1, (int) config('fleet.wialon.engine_hours_report_cache_minutes', 30));

        Cache::put($cacheKey, [
            'hours' => $reportData['hours'] ?? [],
            'mileage' => $reportData['mileage'] ?? [],
            'stat_days' => $reportData['stat_days'] ?? [],
            'source' => $source,
        ], now()->addMinutes($ttlMinutes));
    }

    private function getIntervalWialonEngineHours(
        array $filters,
        $groups,
        array $settings,
        int $from,
        int $to,
        int $periodDays,
        array $hoursByEquipmentId,
        array $mileageByEquipmentId,
        array $equipmentIdByReportKey
    ): ?array {
        $statDaysByEquipmentId = array_fill_keys(array_keys($hoursByEquipmentId), $periodDays);
        $successfulReports = 0;
        $failedOwnershipTypes = [];

        foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownershipType) {
            $group = $groups->get($ownershipType);

            if (! $group) {
                continue;
            }

            try {
                $report = $this->wialon->getReportRows(
                    $settings['resource_id'],
                    $settings['template_id'],
                    $group->wialon_group_id,
                    $from,
                    $to,
                    0,
                    500,
                    16777216,
                    true
                );
                $successfulReports++;
            } catch (Throwable $exception) {
                $failedOwnershipTypes[] = $ownershipType;
                Log::warning('Wialon interval engine hours report failed', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            $engineHoursIndex = $this->engineHoursColumnIndex($report['table'] ?? []);
            $mileageIndex = $this->mileageColumnIndex($report['table'] ?? []);

            foreach ($report['rows'] ?? [] as $row) {
                $cells = $row['c'] ?? [];
                $unitName = $this->reportCellText($cells[0] ?? null);

                if ($unitName === '') {
                    continue;
                }

                $equipmentId = $equipmentIdByReportKey[$this->reportUnitKey($ownershipType, $unitName)] ?? null;

                if (! $equipmentId) {
                    continue;
                }

                $hoursByEquipmentId[$equipmentId] += $this->parseReportHours($cells[$engineHoursIndex] ?? null);
                $mileageByEquipmentId[$equipmentId] += $this->parseReportNumber($cells[$mileageIndex] ?? null);
            }
        }

        if ($successfulReports === 0 && $failedOwnershipTypes === []) {
            return null;
        }

        return [
            'hours' => $hoursByEquipmentId,
            'mileage' => $mileageByEquipmentId,
            'stat_days' => $statDaysByEquipmentId,
            'failed_ownership_types' => array_values(array_unique($failedOwnershipTypes)),
        ];
    }

    private function getDailyWialonEngineHours(
        array $filters,
        $groups,
        array $settings,
        array $hoursByEquipmentId,
        array $mileageByEquipmentId,
        array $equipmentIdByReportKey,
        ?array $ownershipTypes = null
    ): ?array {
        $reportDaysByEquipmentId = [];
        $successfulReports = 0;
        $failedReport = false;
        $ownershipTypes ??= [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE];

        foreach ($ownershipTypes as $ownershipType) {
            $group = $groups->get($ownershipType);

            if (! $group) {
                continue;
            }

            foreach (CarbonPeriod::create($filters['from'], $filters['to']) as $date) {
                $day = Carbon::parse($date->toDateString(), config('app.timezone'));

                try {
                    $report = $this->wialon->getReportRows(
                        $settings['resource_id'],
                        $settings['template_id'],
                        $group->wialon_group_id,
                        $day->copy()->startOfDay()->timestamp,
                        $day->copy()->endOfDay()->timestamp
                    );
                    $successfulReports++;
                } catch (Throwable $exception) {
                    $failedReport = true;
                    Log::warning('Wialon daily engine hours report failed', [
                        'project_id' => $filters['project_id'],
                        'ownership_type' => $ownershipType,
                        'date' => $day->toDateString(),
                        'message' => $exception->getMessage(),
                    ]);
                    continue;
                }

                $engineHoursIndex = $this->engineHoursColumnIndex($report['table'] ?? []);
                $mileageIndex = $this->mileageColumnIndex($report['table'] ?? []);

                foreach ($report['rows'] ?? [] as $row) {
                    $cells = $row['c'] ?? [];
                    $unitName = $this->reportCellText($cells[0] ?? null);

                    if ($unitName === '') {
                        continue;
                    }

                    $equipmentId = $equipmentIdByReportKey[$this->reportUnitKey($ownershipType, $unitName)] ?? null;

                    if (! $equipmentId) {
                        continue;
                    }

                    $hoursByEquipmentId[$equipmentId] += $this->parseReportHours($cells[$engineHoursIndex] ?? null);
                    $mileageByEquipmentId[$equipmentId] += $this->parseReportNumber($cells[$mileageIndex] ?? null);
                    $reportDaysByEquipmentId[$equipmentId][$day->toDateString()] = true;
                }
            }
        }

        if ($successfulReports === 0) {
            return null;
        }

        return [
            'hours' => $hoursByEquipmentId,
            'mileage' => $mileageByEquipmentId,
            'stat_days' => array_map('count', $reportDaysByEquipmentId),
        ];
    }

    private function wialonReportSettings(): array
    {
        $settings = Setting::query()
            ->whereIn('key', ['wialon_resource_id', 'wialon_report_template_id'])
            ->pluck('value', 'key');

        return [
            'resource_id' => (int) ($settings->get('wialon_resource_id') ?: config('fleet.wialon.engine_hours_report_resource_id')),
            'template_id' => (int) ($settings->get('wialon_report_template_id') ?: config('fleet.wialon.engine_hours_report_template_id')),
        ];
    }

    private function engineHoursColumnIndex(?array $table): int
    {
        return $this->reportColumnIndex($table, ['engine hours'], ['duration'], 3);
    }

    private function mileageColumnIndex(?array $table): int
    {
        return $this->reportColumnIndex($table, ['mileage'], ['mileage'], 4);
    }

    private function reportColumnIndex(?array $table, array $headers, array $headerTypes, int $default): int
    {
        $headers = array_map(fn (string $header): string => mb_strtolower($header), $headers);
        $headerTypes = array_map(fn (string $type): string => mb_strtolower($type), $headerTypes);

        foreach (($table['header'] ?? []) as $index => $header) {
            if (in_array(mb_strtolower(trim((string) $header)), $headers, true)) {
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

    private function reportUnitKey(string $ownershipType, string $unitName): string
    {
        return $ownershipType.'|'.mb_strtolower(trim(preg_replace('/\s+/', ' ', $unitName) ?? $unitName));
    }

    private function reportCellText(mixed $cell): string
    {
        if (is_array($cell)) {
            $cell = $cell['t'] ?? $cell['v'] ?? '';
        }

        return trim((string) $cell);
    }

    private function parseReportHours(mixed $cell): float
    {
        if (is_array($cell)) {
            $cell = $cell['v'] ?? $cell['t'] ?? 0;
        }

        if (is_numeric($cell)) {
            return (float) $cell;
        }

        $value = trim((string) $cell);

        if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $value, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) ($matches[3] ?? 0);

            return $hours + ($minutes / 60) + ($seconds / 3600);
        }

        return $this->parseReportNumber($value);
    }

    private function parseReportNumber(mixed $cell): float
    {
        if (is_array($cell)) {
            $cell = $cell['v'] ?? $cell['t'] ?? 0;
        }

        if (is_numeric($cell)) {
            return (float) $cell;
        }

        $value = trim((string) $cell);

        if ($value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]+/u', '', str_replace(["\xc2\xa0", ' '], '', $value)) ?? '';

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
            'from_1_to_7' => '1-7 saat',
            'from_7_to_10' => '7-10 saat',
            'overtime' => '10 saatdan çox - Overtime',
            default => $bucket,
        };
    }

    private function actualWorkHourDashboardBucketLabels(): array
    {
        return [
            'from_7_to_10' => __('app.worked_7_to_10_hours'),
            'from_1_to_7' => __('app.worked_less_than_7_hours'),
            'overtime' => __('app.worked_overtime_hours'),
            'less_than_1' => __('app.worked_less_than_1_hour'),
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

    private function percentChange(float $current, float $previous): float
    {
        if (abs($previous) < 0.01) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
