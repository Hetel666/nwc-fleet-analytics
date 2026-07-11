<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\Project;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
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
            })
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipments.project_id', $projectId))
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

        return Project::query()
            ->leftJoin('equipment_daily_stats', function ($join) use ($filters): void {
                $join->on('equipment_daily_stats.project_id', '=', 'projects.id')
                    ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']]);
            })
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('projects.id', $projectId))
            ->select(
                'projects.id',
                'projects.name',
                DB::raw('COALESCE(SUM(equipment_daily_stats.worked_hours), 0) as hours'),
                DB::raw('COALESCE(AVG(equipment_daily_stats.utilization_percent), 0) as utilization')
            )
            ->groupBy('projects.id', 'projects.name')
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

    public function getMapData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

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
            'equipment' => $this->equipmentQuery($filters)
                ->with(['project', 'type'])
                ->whereNotNull('last_position_json')
                ->get()
                ->map(fn (Equipment $equipment) => [
                    'id' => $equipment->id,
                    'name' => $equipment->name,
                    'type' => $equipment->type?->name,
                    'project' => $equipment->project?->name,
                    'ownership' => $equipment->ownership_type,
                    'position' => $equipment->last_position_json,
                ])
                ->values()
                ->all(),
        ];
    }

    public function getDashboard(array $filters): array
    {
        return [
            'overview' => $this->getOverview($filters),
            'workHourCategories' => $this->getWorkHourCategories($filters),
            'equipmentTypes' => $this->getEquipmentTypeDistribution($filters),
            'averages' => $this->getAverageMetrics($filters),
            'leastWorking' => $this->getLeastWorking($filters),
            'mostWorking' => $this->getMostWorking($filters),
            'projects' => $this->getProjectDistribution($filters),
            'geofenceEvents' => $this->getGeofenceEvents($filters),
            'utilizationTrend' => $this->getUtilizationTrend($filters),
            'mapData' => $this->getMapData($filters),
        ];
    }

    public function normalizeFilters(array $filters): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->toDateString();
        $to = Carbon::parse($filters['to'] ?? now())->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => $filters['project_id'] ?? null,
        ];
    }

    private function statsQuery(array $filters): Builder
    {
        return EquipmentDailyStat::query()
            ->whereBetween('stat_date', [$filters['from'], $filters['to']])
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('project_id', $projectId));
    }

    private function previousStatsQuery(array $filters): Builder
    {
        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);
        $days = $from->diffInDays($to) + 1;

        return EquipmentDailyStat::query()
            ->whereBetween('stat_date', [
                $from->copy()->subDays($days)->toDateString(),
                $to->copy()->subDays($days)->toDateString(),
            ])
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('project_id', $projectId));
    }

    private function equipmentQuery(array $filters): Builder
    {
        return Equipment::query()
            ->where('active', true)
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('project_id', $projectId));
    }

    private function rankedEquipment(array $filters, string $direction, int $limit): array
    {
        $filters = $this->normalizeFilters($filters);

        return Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->join('equipment_daily_stats', 'equipment_daily_stats.equipment_id', '=', 'equipments.id')
            ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']])
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipment_daily_stats.project_id', $projectId))
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

    private function percentChange(float $current, float $previous): float
    {
        if (abs($previous) < 0.01) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
