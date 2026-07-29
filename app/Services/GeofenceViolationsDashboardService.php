<?php

namespace App\Services;

use App\Models\GeofenceViolationReportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GeofenceViolationsDashboardService
{
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
        $activeRows = (clone $query)
            ->where('is_active', true)
            ->get(['equipment_id', 'wialon_unit_id', 'equipment_name']);
        $projectRows = (clone $query)
            ->where(function (Builder $query): void {
                $query->whereNotNull('project_id')->orWhereNotNull('project_name');
            })
            ->get(['project_id', 'project_name']);

        return [
            'rows' => $rows,
            'kpis' => [
                'total_violations' => (clone $query)->count(),
                'active_violations' => $this->uniqueEquipmentCount($activeRows),
                'active_projects' => $this->uniqueProjectCount($projectRows),
                'longest_duration_seconds' => (int) ((clone $query)->max('outside_duration_seconds') ?? 0),
            ],
            'latest_report_at' => (clone $query)->max('report_generated_at'),
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

    public function formatDuration(int $seconds): string
    {
        $row = new GeofenceViolationReportRow(['outside_duration_seconds' => $seconds]);

        return $row->duration_label;
    }

    private function facetQuery(): Builder
    {
        return GeofenceViolationReportRow::query()
            ->where('report_name', (string) config(
                'geofence_violations.report_name',
                GeofenceViolationReportRow::REPORT_NAME
            ))
            ->whereIn('equipment_type', config('geofence_violations.allowed_equipment_types', []))
            ->where('outside_duration_seconds', '>', (int) config(
                'geofence_violations.minimum_duration_seconds',
                GeofenceViolationReportRow::MINIMUM_DURATION_SECONDS
            ))
            ->whereNotNull('exited_at')
            ->whereNotNull('last_confirmed_at')
            ->whereColumn('last_confirmed_at', '>=', 'exited_at');
    }

    private function uniqueEquipmentCount(Collection $rows): int
    {
        return $rows->unique(fn (GeofenceViolationReportRow $row): string => $row->equipment_id
            ? 'equipment:'.$row->equipment_id
            : 'report:'.($row->wialon_unit_id ?: mb_strtolower($row->equipment_name))
        )->count();
    }

    private function uniqueProjectCount(Collection $rows): int
    {
        return $rows->unique(fn (GeofenceViolationReportRow $row): string => $row->project_id
            ? 'project:'.$row->project_id
            : 'report:'.mb_strtolower((string) $row->project_name)
        )->count();
    }
}
