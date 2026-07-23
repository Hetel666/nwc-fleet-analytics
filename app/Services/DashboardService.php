<?php

namespace App\Services;

use App\Models\DailyUnitAggregate;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
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

    private array $wialonEngineHoursReportData = [];

    private array $wialonActualWorkReportData = [];

    private array $wialonGeofenceOutsideRows = [];

    public function __construct(
        private WialonService $wialon,
        private FleetOwnershipStatsService $ownershipStats,
        private FleetEfficiencyService $efficiency,
        private DashboardDailyAverageService $dailyAverages,
        private TopWorkingUnitsService $topWorkingUnits,
        private GeofenceViolationService $geofenceViolations,
    ) {}

    private function shouldUseLiveWialonReports(): bool
    {
        return false;
    }

    public function syncDailyEngineHoursReport(array $filters, bool $force = false): array
    {
        $filters = $this->normalizeFilters($filters);

        if (! $filters['project_id'] || ! $filters['ownership_type'] || $filters['from'] !== $filters['to']) {
            throw new \InvalidArgumentException('Daily report sync requires one project, one ownership type and one date.');
        }

        $ownershipType = $filters['ownership_type'];
        $group = ProjectWialonGroup::query()
            ->where('project_id', $filters['project_id'])
            ->where('ownership_type', $ownershipType)
            ->first();

        if (! $group) {
            throw new \RuntimeException('Wialon group is not configured for the selected project and ownership type.');
        }

        $settings = $this->wialonReportSettings();
        $syncKey = $this->wialonDailyEngineHoursSyncKey($filters, $settings, $group);
        $previousSync = json_decode((string) Setting::query()->where('key', $syncKey)->value('value'), true);

        if (! $force && ($previousSync['status'] ?? null) === 'success') {
            return [
                'status' => 'skipped',
                'date' => $filters['from'],
                'project_id' => $filters['project_id'],
                'ownership_type' => $ownershipType,
                'equipment_count' => (int) ($previousSync['equipment_count'] ?? 0),
            ];
        }

        $equipment = $this->equipmentQuery($filters)->get();
        $equipmentIds = $equipment->pluck('id')->all();
        $hoursByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $mileageByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $engineHoursEquipmentIds = [];
        $mileageEquipmentIds = [];
        $equipmentIdByReportKey = $equipment->mapWithKeys(fn (Equipment $item): array => [
            $this->reportUnitKey($item->ownership_type, $item->name) => $item->id,
        ])->all();

        $reportData = $this->getDailyWialonEngineHours(
            $filters,
            collect([$ownershipType => $group]),
            $settings,
            $hoursByEquipmentId,
            $mileageByEquipmentId,
            $engineHoursEquipmentIds,
            $mileageEquipmentIds,
            $equipmentIdByReportKey,
            [$ownershipType],
            max(5, (int) config('fleet.wialon.report_stats_sync_timeout', 90))
        );

        if ($reportData === null) {
            throw new \RuntimeException('Wialon daily engine hours report could not be generated.');
        }

        $reportedEquipmentIds = array_map('intval', $reportData['reported_equipment_ids'] ?? []);
        $equipmentById = $equipment->keyBy('id');
        $date = $filters['from'];

        DB::transaction(function () use ($reportedEquipmentIds, $equipmentById, $reportData, $date, $equipmentIds): void {
            if ($equipmentIds !== []) {
                EquipmentDailyStat::query()
                    ->where('stat_date', $date)
                    ->whereIn('equipment_id', $equipmentIds)
                    ->where('calculation_source', 'wialon_engine_hours_report')
                    ->delete();

                DailyUnitAggregate::query()
                    ->where('date', $date)
                    ->whereIn('equipment_id', $equipmentIds)
                    ->delete();
            }

            foreach ($reportedEquipmentIds as $equipmentId) {
                $item = $equipmentById->get($equipmentId);

                if (! $item || ! $item->wialon_unit_id) {
                    continue;
                }

                $workedHours = round((float) ($reportData['hours'][$equipmentId] ?? 0.0), 2);
                $distanceKm = round((float) ($reportData['mileage'][$equipmentId] ?? 0.0), 2);
                $utilization = $item->planned_daily_hours > 0
                    ? min(100, ($workedHours / (float) $item->planned_daily_hours) * 100)
                    : 0;
                $dailyStat = EquipmentDailyStat::updateOrCreate(
                    ['stat_date' => $date, 'equipment_id' => $equipmentId],
                    [
                        'project_id' => $item->project_id,
                        'ownership_type' => $item->ownership_type,
                        'worked_hours' => $workedHours,
                        'overtime_hours' => null,
                        'distance_km' => $distanceKm,
                        'utilization_percent' => round($utilization, 2),
                        'calculation_source' => 'wialon_engine_hours_report',
                        'calculation_status' => 'success',
                    ]
                );

                DailyUnitAggregate::updateOrCreate(
                    ['date' => $date, 'unit_id' => $item->wialon_unit_id],
                    [
                        'equipment_id' => $equipmentId,
                        'project_id' => $item->project_id,
                        'equipment_type_id' => $item->equipment_type_id,
                        'ownership_type' => $item->ownership_type,
                        'engine_hours' => $workedHours,
                        'mileage' => $distanceKm,
                        'geofence_outside_hours' => round(((float) $dailyStat->outside_geofence_minutes) / 60, 2),
                    ]
                );
            }
        });

        Setting::updateOrCreate(
            ['key' => $syncKey],
            [
                'value' => json_encode([
                    'status' => 'success',
                    'equipment_count' => count($reportedEquipmentIds),
                    'synced_at' => now(config('app.timezone'))->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
            ]
        );

        Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);

        return [
            'status' => 'synced',
            'date' => $date,
            'project_id' => $filters['project_id'],
            'ownership_type' => $ownershipType,
            'equipment_count' => count($reportedEquipmentIds),
        ];
    }

    public function syncDailyOwnershipEngineHoursReport(array $filters, bool $force = false): array
    {
        $filters = $this->normalizeFilters($filters);

        if ($filters['project_id'] || ! $filters['ownership_type'] || $filters['from'] !== $filters['to']) {
            throw new \InvalidArgumentException('Root ownership report sync requires one ownership type, one date and no project.');
        }

        $ownershipType = $filters['ownership_type'];
        $group = $this->rootOwnershipWialonGroup($ownershipType);

        if (! $group) {
            throw new \RuntimeException('Root Wialon ownership group is not configured.');
        }

        $settings = $this->wialonReportSettings();
        $syncKey = $this->wialonDailyRootEngineHoursSyncKey($filters, $settings, $group);
        $previousSync = json_decode((string) Setting::query()->where('key', $syncKey)->value('value'), true);

        if (! $force && ($previousSync['status'] ?? null) === 'success') {
            return [
                'status' => 'skipped',
                'date' => $filters['from'],
                'project_id' => null,
                'ownership_type' => $ownershipType,
                'equipment_count' => (int) ($previousSync['equipment_count'] ?? 0),
            ];
        }

        $equipment = $this->equipmentQuery($filters)->get();
        $equipmentIds = $equipment->pluck('id')->all();
        $hoursByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $mileageByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $engineHoursEquipmentIds = [];
        $mileageEquipmentIds = [];
        $equipmentIdByReportKey = $equipment->mapWithKeys(fn (Equipment $item): array => [
            $this->reportUnitKey($item->ownership_type, $item->name) => $item->id,
        ])->all();

        $reportData = $this->getDailyWialonEngineHours(
            $filters,
            collect([$ownershipType => $group]),
            $settings,
            $hoursByEquipmentId,
            $mileageByEquipmentId,
            $engineHoursEquipmentIds,
            $mileageEquipmentIds,
            $equipmentIdByReportKey,
            [$ownershipType],
            max(5, (int) config('fleet.wialon.report_stats_sync_timeout', 90))
        );

        if ($reportData === null) {
            throw new \RuntimeException('Wialon root ownership daily engine hours report could not be generated.');
        }

        $reportedEquipmentIds = array_map('intval', $reportData['reported_equipment_ids'] ?? []);
        $equipmentById = $equipment->keyBy('id');
        $date = $filters['from'];

        DB::transaction(function () use ($reportedEquipmentIds, $equipmentById, $reportData, $date, $equipmentIds): void {
            if ($equipmentIds !== []) {
                EquipmentDailyStat::query()
                    ->where('stat_date', $date)
                    ->whereIn('equipment_id', $equipmentIds)
                    ->where('calculation_source', 'wialon_engine_hours_report')
                    ->delete();

                DailyUnitAggregate::query()
                    ->where('date', $date)
                    ->whereIn('equipment_id', $equipmentIds)
                    ->delete();
            }

            foreach ($reportedEquipmentIds as $equipmentId) {
                $item = $equipmentById->get($equipmentId);

                if (! $item || ! $item->wialon_unit_id) {
                    continue;
                }

                $workedHours = round((float) ($reportData['hours'][$equipmentId] ?? 0.0), 2);
                $distanceKm = round((float) ($reportData['mileage'][$equipmentId] ?? 0.0), 2);
                $utilization = $item->planned_daily_hours > 0
                    ? min(100, ($workedHours / (float) $item->planned_daily_hours) * 100)
                    : 0;
                $dailyStat = EquipmentDailyStat::updateOrCreate(
                    ['stat_date' => $date, 'equipment_id' => $equipmentId],
                    [
                        'project_id' => $item->project_id,
                        'ownership_type' => $item->ownership_type,
                        'worked_hours' => $workedHours,
                        'overtime_hours' => null,
                        'distance_km' => $distanceKm,
                        'utilization_percent' => round($utilization, 2),
                        'calculation_source' => 'wialon_engine_hours_report',
                        'calculation_status' => 'success',
                    ]
                );

                DailyUnitAggregate::updateOrCreate(
                    ['date' => $date, 'unit_id' => $item->wialon_unit_id],
                    [
                        'equipment_id' => $equipmentId,
                        'project_id' => $item->project_id,
                        'equipment_type_id' => $item->equipment_type_id,
                        'ownership_type' => $item->ownership_type,
                        'engine_hours' => $workedHours,
                        'mileage' => $distanceKm,
                        'geofence_outside_hours' => round(((float) $dailyStat->outside_geofence_minutes) / 60, 2),
                    ]
                );
            }
        });

        Setting::updateOrCreate(
            ['key' => $syncKey],
            [
                'value' => json_encode([
                    'status' => 'success',
                    'equipment_count' => count($reportedEquipmentIds),
                    'synced_at' => now(config('app.timezone'))->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
            ]
        );

        Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);

        return [
            'status' => 'synced',
            'date' => $date,
            'project_id' => null,
            'ownership_type' => $ownershipType,
            'equipment_count' => count($reportedEquipmentIds),
        ];
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
        $ownershipCounts = $this->equipmentQuery($filters)
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->select('equipments.ownership_type', DB::raw('COUNT(DISTINCT equipments.id) as total'))
            ->groupBy('equipments.ownership_type')
            ->pluck('total', 'equipments.ownership_type');
        $ownershipShare = collect([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->map(fn (string $ownership): array => [
                'label' => $ownership,
                'count' => (int) ($ownershipCounts[$ownership] ?? 0),
            ])
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
                    ->whereDate('equipment_daily_stats.stat_date', '>=', $filters['from'])
                    ->whereDate('equipment_daily_stats.stat_date', '<=', $filters['to']);

                if ($filters['project_id']) {
                    $join->where('equipment_daily_stats.project_id', $filters['project_id']);
                }

                if ($filters['ownership_type']) {
                    $join->where('equipment_daily_stats.ownership_type', $filters['ownership_type']);
                }
            })
            ->where('equipments.active', true)
            ->visibleInDashboard()
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

        $rows = $this->equipmentQuery($filters)
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
        $reportData = $this->getWialonEngineHoursReportData($filters);

        if ($reportData !== null) {
            return $this->buildOwnershipAverageMetrics(
                $reportData['equipment'],
                $reportData['hours'],
                $reportData['mileage'] ?? [],
                $reportData['engine_hours_equipment_ids'] ?? ($reportData['reported_equipment_ids'] ?? []),
                $reportData['mileage_equipment_ids'] ?? ($reportData['reported_equipment_ids'] ?? []),
                'Wialon report'
            );
        }

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

    public function getLeastWorking(array $filters, int $limit = 20): array
    {
        return $this->topWorkingUnits->least($this->normalizeFilters($filters), $limit);
    }

    public function getMostWorking(array $filters, int $limit = 20): array
    {
        return $this->topWorkingUnits->most($this->normalizeFilters($filters), $limit);
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
            $reportData = $this->getWialonActualWorkReportData($filters);

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
        return $this->efficiency->projectRowsByOwnership($this->normalizeFilters($filters));
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
            'equipmentTypesByOwnership' => $this->getEquipmentTypeDistributionByOwnership([...$filters, '_include_type_id' => true]),
            'averages' => $this->getAverageMetrics($filters),
            'averageMetricsByOwnership' => $this->getAverageMetricsByOwnership($filters),
            'dailyAverageDashboards' => [
                'engine_hours' => $this->dailyAverages->dashboardData($filters, 'engine_hours'),
                'mileage' => $this->dailyAverages->dashboardData($filters, 'mileage'),
            ],
            'leastWorking' => $this->getLeastWorking($filters),
            'mostWorking' => $this->getMostWorking($filters),
            'projects' => $this->getProjectDistribution($filters),
            'projectActualWorkHourCategoriesByOwnership' => $this->getProjectActualWorkHourCategoriesByOwnership($filters),
            'projectOwnershipComparison' => $this->getProjectOwnershipComparison($filters),
            'geofenceViolations' => $this->geofenceViolations->summary($filters),
            'utilizationTrend' => $this->getUtilizationTrend($filters),
            'utilizationTrendByOwnership' => $this->getUtilizationTrendByOwnership($filters),
        ];
    }

    private function dashboardCacheKey(array $filters): string
    {
        return 'dashboard:aggregate:'.md5(json_encode([
            'version' => 19,
            'data_version' => (int) Cache::get('dashboard:data-version', 1),
            'filters' => $filters,
        ]));
    }

    public function getDashboardExport(array $filters, string $block): array
    {
        [$filters, $block] = $this->normalizeExportRequest($filters, $block);
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

        if (! in_array($block, ['least-working', 'most-working'], true)) {
            $sections[] = [
                'title' => __('app.equipment_details'),
                'columns' => $this->dashboardExportDetailColumns($block),
                'rows' => $this->dashboardExportDetailRows($filters, $block),
            ];
        }
        $filename = match (true) {
            in_array($block, ['least-working', 'most-working'], true) => $this->topWorkingExportFilename($block, $filters),
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

        $projectId = $filters['project_id'] ?? null;
        $projectId = $projectId === '' || $projectId === 'all' ? null : $projectId;

        return [
            'from' => $from,
            'to' => $to,
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

        return [$this->normalizeFilters($filters), $block];
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
            'least-working' => __('app.least_working'),
            'most-working' => __('app.most_working'),
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

    private function topWorkingExportFilename(string $block, array $filters): string
    {
        $name = $block === 'most-working' ? 'top-20-cox-isleyenler' : 'top-20-az-isleyenler';
        $period = $filters['from'] === $filters['to']
            ? $filters['from']
            : $filters['from'].'_'.$filters['to'];

        return $name.'-'.$period.'.xlsx';
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
            'least-working', 'most-working' => $this->topWorkingUnits->exportColumns($filters),
            'equipment-types-nwc', 'equipment-types-icare' => [__('app.type'), 'Say', 'Faiz'],
            'equipment-types' => [__('app.ownership'), __('app.type'), 'Say'],
            'average-engine-hours', 'average-mileage' => ['Tarix', 'NWC', __('app.ownership_icare'), 'Orta'],
            'project-averages' => ['Göstərici', 'Tip', 'Say', 'Dəyərlər', 'Mənbə'],
            'ownership-share' => [__('app.ownership'), 'Say', 'Faiz'],
            'geofence-analysis' => ['Layihə', 'Texnika sayı'],
            'utilization-trend' => ['Tarix', 'NWC (%)', __('app.ownership_icare').' (%)'],
            'actual-work-hours-nwc', 'actual-work-hours-icare' => [__('app.status'), 'Say', 'Faiz'],
            'actual-work-hour-categories' => array_merge([__('app.project')], array_values($this->actualWorkHourDashboardBucketLabels()), ['Cəmi', 'Məlumatı olmayan texnika']),
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
            'least-working' => $this->topWorkingUnits->exportRows($filters, 'least', 20),
            'most-working' => $this->topWorkingUnits->exportRows($filters, 'most', 20),
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
                'Gündüz statusu',
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
            return $this->dashboardEquipmentTypeExportRows($filters);
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

        if ($block === 'least-working' || $block === 'most-working') {
            return [];
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

        foreach ($this->getProjectActualWorkHourCategoriesByOwnership($filters)[$ownership] ?? [] as $row) {
            foreach ($keys as $key) {
                $summary[$key] += (int) ($row[$key] ?? 0);
            }
        }

        $total = array_sum($summary);

        return collect($keys)
            ->map(fn (string $key): array => [
                $labels[$key],
                $summary[$key],
                $this->dashboardExportPercent($summary[$key], $total),
            ])
            ->push(['Cəmi', $total, $total > 0 ? '100.0%' : '0.0%'])
            ->all();
    }

    private function dashboardExportPercent(int|float $value, int|float $total): string
    {
        return $total > 0 ? number_format(((float) $value / (float) $total) * 100, 1, '.', '').'%' : '0.0%';
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
                ->with(['type:id,name', 'project:id,name,code', 'projectWialonGroup:id,name,wialon_group_id'])
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

        if ($block === 'least-working' || $block === 'most-working') {
            $rows = array_slice($rows, 0, 20);
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

    private function dashboardEquipmentTypeExportRows(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $equipment = $this->equipmentQuery($filters)
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
            if ($block === 'least-working') {
                return ($first['sort_hours'] <=> $second['sort_hours'])
                    ?: strnatcasecmp($first['sort_name'], $second['sort_name']);
            }

            if ($block === 'most-working') {
                return ($second['sort_hours'] <=> $first['sort_hours'])
                    ?: strnatcasecmp($first['sort_name'], $second['sort_name']);
            }

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
                ->whereDate('stat_date', '>=', $filters['from'])
                ->whereDate('stat_date', '<=', $filters['to']),
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
                ->whereDate('stat_date', '>=', $from->copy()->subDays($days)->toDateString())
                ->whereDate('stat_date', '<=', $to->copy()->subDays($days)->toDateString()),
            $filters
        );
    }

    private function equipmentQuery(array $filters): Builder
    {
        return Equipment::query()
            ->where('equipments.active', true)
            ->visibleInDashboard()
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
            ->visibleInDashboard()
            ->whereDate('equipment_daily_stats.stat_date', '>=', $filters['from'])
            ->whereDate('equipment_daily_stats.stat_date', '<=', $filters['to'])
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

        if (! $this->shouldUseLiveWialonReports()) {
            return null;
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
                    max(5, (int) config('fleet.wialon.geofence_outside_report_timeout', 10))
                );
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

            $engineTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'engine', true);
            $geofenceTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'geofence');

            if ($engineTable === null || $geofenceTable === null) {
                Log::warning('Wialon geofence outside report tables missing', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'tables' => collect($report['tables'] ?? [])->map(fn (array $item): string => (string) (($item['table']['label'] ?? null) ?: ($item['table']['name'] ?? '')))->all(),
                ]);

                continue;
            }

            $successfulReports++;

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
            $engineSeconds = $this->parseWialonEngineHoursToSeconds(
                $this->reportRecordValue($record, [
                    'Engine hours',
                    'Motor saatı',
                    'Moto hours',
                    'M/h',
                    'Worked hours',
                    'İşləmə saatı',
                    'Duration',
                    'Saat',
                ])
                    ?? ($cells[$engineIndex] ?? null)
            );

            if ($engineSeconds === null) {
                continue;
            }

            $date = (string) ($record['_date'] ?? '');
            $rowKey = $date.'|'.$equipmentKey;

            $rows[$rowKey] ??= [
                'equipment_key' => $equipmentKey,
                'grouping' => (string) $grouping,
                'vendor' => (string) ($this->reportRecordValue($record, ['Vendor', 'Mülkiyyət', 'Mulkiyyet']) ?? ''),
                'date' => $date,
                'engine_hours' => 0.0,
                'engine_seconds' => 0,
            ];
            $rows[$rowKey]['engine_hours'] += $engineSeconds / 3600;
            $rows[$rowKey]['engine_seconds'] += $engineSeconds;
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

    private function wialonReportTableByKind(array $tables, string $kind, bool $strict = false): ?array
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

        if ($strict) {
            return null;
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
            'Время стоянки',
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
                (str_contains($key, 'duration') || str_contains($key, 'saat') || str_contains($key, 'hours') || str_contains($key, 'время стоянки'))
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

            try {
                $templateId = (int) Cache::remember(
                    'dashboard:wialon-geofence-outside-template:'.md5($resourceId.'|'.$templateName),
                    now()->addHours(6),
                    fn (): int => (int) ($this->wialon->findReportTemplateIdByName($resourceId, $templateName) ?? 0)
                );
            } catch (Throwable $exception) {
                Log::warning('Wialon geofence outside report template lookup failed', [
                    'resource_id' => $resourceId,
                    'template_name' => $templateName,
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }
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
            'version' => 3,
            'filters' => $filters,
            'settings' => $settings,
            'groups' => $groupIds,
        ]));
    }

    private function wialonActualWorkReportSettings(): ?array
    {
        $resourceId = (int) config('fleet.wialon.actual_work_report_resource_id');
        $templateId = (int) config('fleet.wialon.actual_work_report_template_id');

        if ($resourceId <= 0) {
            return null;
        }

        if ($templateId <= 0) {
            $templateName = (string) config('fleet.wialon.actual_work_report_template_name', '');

            if ($templateName === '') {
                return null;
            }

            $templateId = (int) Cache::remember(
                'dashboard:wialon-actual-work-template:'.md5($resourceId.'|'.$templateName),
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

    private function wialonActualWorkReportCacheKey(array $filters, array $settings, array $groupIds): string
    {
        ksort($groupIds);

        return 'dashboard:wialon-actual-work:'.md5(json_encode([
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
                    ->visibleInDashboard()
                    ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId));
            });
    }

    private function getActualWorkHourCategoriesFromWialonReport(array $filters): ?array
    {
        $reportData = $this->getWialonActualWorkReportData($filters);

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

    private function getWialonActualWorkReportData(array $filters): ?array
    {
        $filters = $this->normalizeFilters($filters);

        if (! $filters['project_id']) {
            return null;
        }

        $cacheKey = md5(json_encode($filters));

        if (array_key_exists($cacheKey, $this->wialonActualWorkReportData)) {
            return $this->wialonActualWorkReportData[$cacheKey];
        }

        $groups = ProjectWialonGroup::query()
            ->where('project_id', $filters['project_id'])
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('ownership_type', $ownershipType))
            ->get()
            ->keyBy('ownership_type');

        if ($groups->isEmpty()) {
            return $this->wialonActualWorkReportData[$cacheKey] = null;
        }

        $settings = $this->wialonActualWorkReportSettings();

        if ($settings === null) {
            return $this->wialonActualWorkReportData[$cacheKey] = null;
        }

        $equipment = $this->equipmentQuery($filters)
            ->with(['type:id,name', 'project:id,name,code', 'projectWialonGroup:id,name,wialon_group_id'])
            ->get();

        $periodStart = Carbon::parse($filters['from'], config('app.timezone'))->startOfDay();
        $periodEnd = Carbon::parse($filters['to'], config('app.timezone'))->endOfDay();
        $periodDays = max(1, (int) $periodStart->diffInDays($periodEnd) + 1);

        if ($equipment->isEmpty()) {
            return $this->wialonActualWorkReportData[$cacheKey] = [
                'equipment' => $equipment,
                'hours' => [],
                'stat_days' => [],
                'period_days' => $periodDays,
                'source' => 'empty',
            ];
        }

        $hoursByEquipmentId = [];
        $statDatesByEquipmentId = [];
        $equipmentIdByReportKey = [];

        foreach ($equipment as $item) {
            $hoursByEquipmentId[$item->id] = 0.0;
            $equipmentIdByReportKey[$this->reportUnitKey($item->ownership_type, $item->name)] = $item->id;
        }

        $persistentCacheKey = $this->wialonActualWorkReportCacheKey(
            $filters,
            $settings,
            $groups->pluck('wialon_group_id', 'ownership_type')->all()
        );
        $cachedReportData = Cache::get($persistentCacheKey);

        if (is_array($cachedReportData) && isset($cachedReportData['hours'], $cachedReportData['stat_days'])) {
            return $this->wialonActualWorkReportData[$cacheKey] = [
                'equipment' => $equipment,
                'hours' => $cachedReportData['hours'],
                'stat_days' => $cachedReportData['stat_days'],
                'period_days' => $periodDays,
                'source' => $cachedReportData['source'] ?? 'cache',
            ];
        }

        if (! $this->shouldUseLiveWialonReports()) {
            return $this->wialonActualWorkReportData[$cacheKey] = null;
        }

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
                    true,
                    max(5, (int) config('fleet.wialon.actual_work_report_timeout', 10))
                );
            } catch (Throwable $exception) {
                $failedReports++;
                Log::warning('Wialon actual work report failed', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $engineTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'engine', true);

            if ($engineTable === null) {
                Log::warning('Wialon actual work report table missing', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'tables' => collect($report['tables'] ?? [])->map(fn (array $item): string => (string) (($item['table']['label'] ?? null) ?: ($item['table']['name'] ?? '')))->all(),
                ]);

                continue;
            }

            $successfulReports++;

            foreach ($this->engineRowsByReportKey($engineTable, $ownershipType, $equipmentIdByReportKey) as $engineRow) {
                $equipmentKey = $engineRow['equipment_key'];
                $equipmentId = $equipmentIdByReportKey[$equipmentKey] ?? null;

                if (! $equipmentId) {
                    continue;
                }

                $hoursByEquipmentId[$equipmentId] += (float) $engineRow['engine_hours'];

                if (($engineRow['date'] ?? '') !== '') {
                    $statDatesByEquipmentId[$equipmentId][$engineRow['date']] = true;
                }
            }
        }

        if ($successfulReports === 0) {
            return $this->wialonActualWorkReportData[$cacheKey] = null;
        }

        $statDaysByEquipmentId = [];

        foreach ($hoursByEquipmentId as $equipmentId => $hours) {
            $statDaysByEquipmentId[$equipmentId] = isset($statDatesByEquipmentId[$equipmentId])
                ? max(1, count($statDatesByEquipmentId[$equipmentId]))
                : $periodDays;
        }

        $reportData = [
            'hours' => $hoursByEquipmentId,
            'stat_days' => $statDaysByEquipmentId,
            'source' => 'actual_work_report',
        ];

        if ($failedReports === 0) {
            Cache::put(
                $persistentCacheKey,
                $reportData,
                now()->addMinutes(max(1, (int) config('fleet.wialon.actual_work_report_cache_minutes', 30)))
            );
        }

        return $this->wialonActualWorkReportData[$cacheKey] = [
            'equipment' => $equipment,
            ...$reportData,
            'period_days' => $periodDays,
        ];
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
                'engine_hours_equipment_ids' => [],
                'mileage_equipment_ids' => [],
                'period_days' => $periodDays,
                'source' => 'empty',
            ];
        }

        $hoursByEquipmentId = [];
        $mileageByEquipmentId = [];
        $engineHoursEquipmentIds = [];
        $mileageEquipmentIds = [];
        $equipmentIdByReportKey = [];

        foreach ($equipment as $item) {
            $hoursByEquipmentId[$item->id] = 0.0;
            $mileageByEquipmentId[$item->id] = 0.0;
            $equipmentIdByReportKey[$this->reportUnitKey($item->ownership_type, $item->name)] = $item->id;
        }

        $settings = $this->wialonReportSettings();
        $storedReportData = $this->getStoredWialonEngineHoursReportData(
            $filters,
            $groups,
            $settings,
            $equipment,
            $periodDays
        );

        if ($storedReportData !== null) {
            return $this->wialonEngineHoursReportData[$cacheKey] = [
                'equipment' => $equipment,
                ...$storedReportData,
                'period_days' => $periodDays,
                'source' => 'stored_daily_reports',
            ];
        }

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
                'engine_hours_equipment_ids' => $cachedReportData['engine_hours_equipment_ids'] ?? ($cachedReportData['reported_equipment_ids'] ?? []),
                'mileage_equipment_ids' => $cachedReportData['mileage_equipment_ids'] ?? ($cachedReportData['reported_equipment_ids'] ?? []),
                'reported_equipment_ids' => $cachedReportData['reported_equipment_ids'] ?? [],
                'period_days' => $periodDays,
                'source' => $cachedReportData['source'] ?? 'cache',
            ];
        }

        if (! $this->shouldUseLiveWialonReports()) {
            return $this->wialonEngineHoursReportData[$cacheKey] = null;
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
            $engineHoursEquipmentIds,
            $mileageEquipmentIds,
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
                    array_fill_keys($intervalData['engine_hours_equipment_ids'] ?? [], true),
                    array_fill_keys($intervalData['mileage_equipment_ids'] ?? [], true),
                    $equipmentIdByReportKey,
                    $failedOwnershipTypes
                );

                if ($dailyData !== null) {
                    $mergedData = [
                        'hours' => $dailyData['hours'],
                        'mileage' => $dailyData['mileage'],
                        'stat_days' => $dailyData['stat_days'] + $intervalData['stat_days'],
                        'engine_hours_equipment_ids' => array_values(array_unique(array_merge(
                            $intervalData['engine_hours_equipment_ids'] ?? [],
                            $dailyData['engine_hours_equipment_ids'] ?? []
                        ))),
                        'mileage_equipment_ids' => array_values(array_unique(array_merge(
                            $intervalData['mileage_equipment_ids'] ?? [],
                            $dailyData['mileage_equipment_ids'] ?? []
                        ))),
                        'reported_equipment_ids' => array_values(array_unique(array_merge(
                            $intervalData['reported_equipment_ids'] ?? [],
                            $dailyData['reported_equipment_ids'] ?? []
                        ))),
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

        $dailyData = $this->getDailyWialonEngineHours(
            $filters,
            $groups,
            $settings,
            $hoursByEquipmentId,
            $mileageByEquipmentId,
            $engineHoursEquipmentIds,
            $mileageEquipmentIds,
            $equipmentIdByReportKey
        );

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
            'version' => 6,
            'filters' => $filters,
            'settings' => $settings,
            'groups' => $groupIds,
        ]));
    }

    private function getStoredWialonEngineHoursReportData(
        array $filters,
        $groups,
        array $settings,
        $equipment,
        int $periodDays
    ): ?array {
        $expectedSyncKeys = [];

        foreach ($groups as $group) {
            foreach (CarbonPeriod::create($filters['from'], $filters['to']) as $date) {
                $dailyFilters = $filters;
                $dailyFilters['from'] = $date->toDateString();
                $dailyFilters['to'] = $date->toDateString();
                $dailyFilters['ownership_type'] = $group->ownership_type;
                $expectedSyncKeys[] = $this->wialonDailyEngineHoursSyncKey($dailyFilters, $settings, $group);
            }
        }

        if ($expectedSyncKeys === []) {
            return null;
        }

        $syncValues = Setting::query()->whereIn('key', $expectedSyncKeys)->pluck('value', 'key');

        foreach ($expectedSyncKeys as $syncKey) {
            $syncData = json_decode((string) $syncValues->get($syncKey), true);

            if (($syncData['status'] ?? null) !== 'success') {
                return null;
            }
        }

        $hoursByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $mileageByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $statDaysByEquipmentId = [];
        $equipmentIds = array_keys($hoursByEquipmentId);

        $rows = EquipmentDailyStat::query()
            ->whereDate('stat_date', '>=', $filters['from'])
            ->whereDate('stat_date', '<=', $filters['to'])
            ->where('project_id', $filters['project_id'])
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('ownership_type', $ownershipType))
            ->whereIn('equipment_id', $equipmentIds)
            ->select(
                'equipment_id',
                DB::raw('SUM(worked_hours) as hours'),
                DB::raw('SUM(distance_km) as mileage'),
                DB::raw('COUNT(DISTINCT stat_date) as stat_days')
            )
            ->groupBy('equipment_id')
            ->get();

        foreach ($rows as $row) {
            $equipmentId = (int) $row->equipment_id;
            $hoursByEquipmentId[$equipmentId] = (float) $row->hours;
            $mileageByEquipmentId[$equipmentId] = (float) $row->mileage;
            $statDaysByEquipmentId[$equipmentId] = min($periodDays, (int) $row->stat_days);
        }

        $reportedEquipmentIds = $rows->pluck('equipment_id')->map(fn ($id): int => (int) $id)->all();

        return [
            'hours' => $hoursByEquipmentId,
            'mileage' => $mileageByEquipmentId,
            'stat_days' => $statDaysByEquipmentId,
            'engine_hours_equipment_ids' => $reportedEquipmentIds,
            'mileage_equipment_ids' => $reportedEquipmentIds,
            'reported_equipment_ids' => $reportedEquipmentIds,
        ];
    }

    private function wialonDailyEngineHoursSyncKey(
        array $filters,
        array $settings,
        ProjectWialonGroup $group
    ): string {
        return 'wialon_daily_engine_sync:'.sha1(json_encode([
            'version' => 1,
            'resource_id' => $settings['resource_id'],
            'template_id' => $settings['template_id'],
            'project_id' => $filters['project_id'],
            'ownership_type' => $group->ownership_type,
            'group_id' => $group->wialon_group_id,
            'date' => $filters['from'],
        ]));
    }

    private function wialonDailyRootEngineHoursSyncKey(array $filters, array $settings, object $group): string
    {
        return 'wialon_daily_root_engine_sync:'.sha1(json_encode([
            'version' => 1,
            'resource_id' => $settings['resource_id'],
            'template_id' => $settings['template_id'],
            'scope' => 'root_ownership_group',
            'ownership_type' => $group->ownership_type,
            'group_id' => $group->wialon_group_id,
            'date' => $filters['from'],
        ]));
    }

    private function rootOwnershipWialonGroup(string $ownershipType): ?object
    {
        return $this->rootOwnershipWialonGroups()->get($ownershipType);
    }

    private function rootOwnershipWialonGroups()
    {
        return collect([
            Equipment::OWNERSHIP_NWC => (object) [
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'wialon_group_id' => (string) config('fleet.wialon.nwc_group_id'),
                'name' => '+NWC+',
            ],
            Equipment::OWNERSHIP_ICARE => (object) [
                'ownership_type' => Equipment::OWNERSHIP_ICARE,
                'wialon_group_id' => (string) config('fleet.wialon.icare_group_id'),
                'name' => '+İcarə+',
            ],
        ])->filter(fn (object $group): bool => trim($group->wialon_group_id) !== '');
    }

    private function cacheWialonEngineHoursReportData(string $cacheKey, array $reportData, string $source): void
    {
        $ttlMinutes = max(1, (int) config('fleet.wialon.engine_hours_report_cache_minutes', 30));

        Cache::put($cacheKey, [
            'hours' => $reportData['hours'] ?? [],
            'mileage' => $reportData['mileage'] ?? [],
            'stat_days' => $reportData['stat_days'] ?? [],
            'engine_hours_equipment_ids' => $reportData['engine_hours_equipment_ids'] ?? [],
            'mileage_equipment_ids' => $reportData['mileage_equipment_ids'] ?? [],
            'reported_equipment_ids' => $reportData['reported_equipment_ids'] ?? [],
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
        array $engineHoursEquipmentIds,
        array $mileageEquipmentIds,
        array $equipmentIdByReportKey
    ): ?array {
        $statDaysByEquipmentId = array_fill_keys(array_keys($hoursByEquipmentId), $periodDays);
        $reportedEquipmentIds = [];
        $successfulReports = 0;
        $failedOwnershipTypes = [];

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
                    $from,
                    $to,
                    500,
                    16777216,
                    true,
                    max(5, (int) config('fleet.wialon.engine_hours_report_timeout', 10))
                );
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

            $engineTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'engine', true);

            if ($engineTable === null) {
                $failedOwnershipTypes[] = $ownershipType;
                Log::warning('Wialon interval engine hours table missing', [
                    'project_id' => $filters['project_id'],
                    'ownership_type' => $ownershipType,
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'tables' => collect($report['tables'] ?? [])->map(fn (array $item): string => (string) (($item['table']['label'] ?? null) ?: ($item['table']['name'] ?? '')))->all(),
                ]);

                continue;
            }

            $successfulReports++;
            $engineHoursIndex = $this->engineHoursColumnIndex($engineTable['table'] ?? []);
            $mileageIndex = $this->mileageColumnIndex($engineTable['table'] ?? []);

            foreach ($engineTable['rows'] ?? [] as $row) {
                $cells = $row['c'] ?? [];
                $unitName = $this->reportCellText($cells[0] ?? null);

                if ($unitName === '') {
                    continue;
                }

                $equipmentId = $equipmentIdByReportKey[$this->reportUnitKey($ownershipType, $unitName)] ?? null;

                if (! $equipmentId) {
                    continue;
                }

                $engineSeconds = $this->parseWialonEngineHoursToSeconds($cells[$engineHoursIndex] ?? null);
                $mileageKm = $this->parseWialonMileageToKm($cells[$mileageIndex] ?? null);

                if ($engineSeconds !== null) {
                    $hoursByEquipmentId[$equipmentId] += $engineSeconds / 3600;
                    $engineHoursEquipmentIds[(int) $equipmentId] = true;
                    $reportedEquipmentIds[(int) $equipmentId] = true;
                }

                if ($mileageKm !== null) {
                    $mileageByEquipmentId[$equipmentId] += $mileageKm;
                    $mileageEquipmentIds[(int) $equipmentId] = true;
                    $reportedEquipmentIds[(int) $equipmentId] = true;
                }
            }
        }

        if ($successfulReports === 0) {
            return null;
        }

        return [
            'hours' => $hoursByEquipmentId,
            'mileage' => $mileageByEquipmentId,
            'stat_days' => $statDaysByEquipmentId,
            'engine_hours_equipment_ids' => array_keys($engineHoursEquipmentIds),
            'mileage_equipment_ids' => array_keys($mileageEquipmentIds),
            'reported_equipment_ids' => array_keys($reportedEquipmentIds),
            'failed_ownership_types' => array_values(array_unique($failedOwnershipTypes)),
        ];
    }

    private function getDailyWialonEngineHours(
        array $filters,
        $groups,
        array $settings,
        array $hoursByEquipmentId,
        array $mileageByEquipmentId,
        array $engineHoursEquipmentIds,
        array $mileageEquipmentIds,
        array $equipmentIdByReportKey,
        ?array $ownershipTypes = null,
        ?int $requestTimeout = null
    ): ?array {
        $reportDaysByEquipmentId = [];
        $reportedEquipmentIds = [];
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
                    $report = $this->wialon->getReportTablesRows(
                        $settings['resource_id'],
                        $settings['template_id'],
                        $group->wialon_group_id,
                        $day->copy()->startOfDay()->timestamp,
                        $day->copy()->endOfDay()->timestamp,
                        500,
                        16777216,
                        false,
                        max(5, $requestTimeout ?? (int) config('fleet.wialon.daily_engine_hours_report_timeout', 30))
                    );
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

                $engineTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'engine', true);

                if ($engineTable === null) {
                    $failedReport = true;
                    Log::warning('Wialon daily engine hours table missing', [
                        'project_id' => $filters['project_id'],
                        'ownership_type' => $ownershipType,
                        'date' => $day->toDateString(),
                        'tables' => collect($report['tables'] ?? [])->map(fn (array $item): string => (string) (($item['table']['label'] ?? null) ?: ($item['table']['name'] ?? '')))->all(),
                    ]);

                    continue;
                }

                $successfulReports++;
                $engineHoursIndex = $this->engineHoursColumnIndex($engineTable['table'] ?? []);
                $mileageIndex = $this->mileageColumnIndex($engineTable['table'] ?? []);

                foreach ($engineTable['rows'] ?? [] as $row) {
                    $cells = $row['c'] ?? [];
                    $unitName = $this->reportCellText($cells[0] ?? null);

                    if ($unitName === '') {
                        continue;
                    }

                    $equipmentId = $equipmentIdByReportKey[$this->reportUnitKey($ownershipType, $unitName)] ?? null;

                    if (! $equipmentId) {
                        continue;
                    }

                    $engineSeconds = $this->parseWialonEngineHoursToSeconds($cells[$engineHoursIndex] ?? null);
                    $mileageKm = $this->parseWialonMileageToKm($cells[$mileageIndex] ?? null);

                    if ($engineSeconds !== null) {
                        $hoursByEquipmentId[$equipmentId] += $engineSeconds / 3600;
                        $reportDaysByEquipmentId[$equipmentId][$day->toDateString()] = true;
                        $engineHoursEquipmentIds[(int) $equipmentId] = true;
                        $reportedEquipmentIds[(int) $equipmentId] = true;
                    }

                    if ($mileageKm !== null) {
                        $mileageByEquipmentId[$equipmentId] += $mileageKm;
                        $mileageEquipmentIds[(int) $equipmentId] = true;
                        $reportedEquipmentIds[(int) $equipmentId] = true;
                    }
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
            'engine_hours_equipment_ids' => array_keys($engineHoursEquipmentIds),
            'mileage_equipment_ids' => array_keys($mileageEquipmentIds),
            'reported_equipment_ids' => array_keys($reportedEquipmentIds),
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
        return $this->reportColumnIndex($table, [
            'engine hours',
            'motor saatı',
            'moto hours',
            'm/h',
            'worked hours',
            'işləmə saatı',
            'duration',
            'saat',
        ], ['duration'], 3);
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
        $seconds = $this->parseWialonEngineHoursToSeconds($cell);

        return $seconds !== null ? $seconds / 3600 : 0.0;
    }

    private function parseWialonEngineHoursToSeconds(mixed $cell): ?int
    {
        if (is_array($cell)) {
            $textValue = $cell['t'] ?? null;

            if ($textValue !== null) {
                $parsedText = $this->parseWialonEngineHoursToSeconds($textValue);

                if ($parsedText !== null) {
                    return $parsedText;
                }
            }

            return $this->parseWialonEngineHoursToSeconds($cell['v'] ?? null);
        }

        if ($cell === null) {
            return null;
        }

        if (is_int($cell) || is_float($cell)) {
            return max(0, (int) round((float) $cell));
        }

        $value = trim((string) $cell);

        if ($value === '' || in_array($value, ['-', '-----'], true)) {
            return null;
        }

        if (preg_match('/^(?:(\d+)\s+day[s]?\s+)?(\d+):(\d{2})(?::(\d{2}))?$/i', $value, $matches)) {
            $days = (int) ($matches[1] ?? 0);
            $hours = (int) $matches[2];
            $minutes = (int) $matches[3];
            $seconds = (int) ($matches[4] ?? 0);

            return max(0, (($days * 24 + $hours) * 3600) + ($minutes * 60) + $seconds);
        }

        if (preg_match('/^\d+(?:[,.]\d+)?$/', $value)) {
            return max(0, (int) round($this->parseReportNumber($value) * 3600));
        }

        return null;
    }

    private function parseWialonMileageToKm(mixed $cell): ?float
    {
        if (is_array($cell)) {
            $textValue = $cell['t'] ?? null;

            if ($textValue !== null) {
                $parsedText = $this->parseWialonMileageToKm($textValue);

                if ($parsedText !== null) {
                    return $parsedText;
                }
            }

            return $this->parseWialonMileageToKm($cell['v'] ?? null);
        }

        if ($cell === null) {
            return null;
        }

        if (is_int($cell) || is_float($cell)) {
            return max(0.0, (float) $cell);
        }

        $value = trim((string) $cell);

        if ($value === '' || in_array($value, ['-', '-----'], true)) {
            return null;
        }

        $normalized = preg_replace('/[^\d,.\-]+/u', '', str_replace(["\xc2\xa0", ' '], '', $value)) ?? '';

        if ($normalized === '' || ! preg_match('/-?\d+(?:[,.]\d+)?/', $normalized)) {
            return null;
        }

        return max(0.0, $this->parseReportNumber($value));
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
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
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
            'less_than_1', 'less_than_1_hour' => '1 saatdan az',
            'from_1_to_7', 'less_than_7_hours' => '7 saatdan az',
            'from_7_to_10', 'between_7_and_10_hours' => '7-10 saat işləyən',
            'over_10_hours' => __('app.worked_over_10_hours'),
            'overtime' => __('app.worked_overtime_hours'),
            default => $bucket,
        };
    }

    private function actualWorkHourDashboardBucketLabels(): array
    {
        return [
            'less_than_1_hour' => __('app.worked_less_than_1_hour'),
            'less_than_7_hours' => __('app.worked_less_than_7_hours'),
            'between_7_and_10_hours' => __('app.worked_7_to_10_hours'),
            'over_10_hours' => __('app.worked_over_10_hours'),
            'overtime' => __('app.worked_overtime_hours'),
        ];
    }

    private function actualWorkHourBucket(float $hours): string
    {
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
