<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeofenceViolationsDrilldownRequest;
use App\Models\Project;
use App\Services\GeofenceViolationsDashboardService;
use Illuminate\Http\JsonResponse;

class GeofenceViolationsDrilldownController extends Controller
{
    public function __invoke(
        GeofenceViolationsDrilldownRequest $request,
        GeofenceViolationsDashboardService $dashboard
    ): JsonResponse {
        $filters = $request->filters();
        $rows = $dashboard->paginateRows($filters, $request->perPage());
        $projectName = $filters['project_id'] !== null
            ? Project::query()->whereKey($filters['project_id'])->value('name')
            : null;
        $outsideLabel = 'Layihədən kənarda / Məlumatsız';

        return response()->json([
            'title' => $projectName
                ? $projectName.' - Geofence Pozuntuları'
                : 'Geofence Pozuntuları',
            'filters' => array_filter([
                'Dövr' => $filters['date_from'].' - '.$filters['date_to'],
                'Ev layihəsi' => $projectName,
                'Cari layihə' => $outsideLabel,
            ]),
            'summary' => ['total' => $rows->total()],
            'columns' => [
                'number' => '№',
                'equipment_name' => 'Texnika',
                'equipment_type' => 'Texnika növü',
                'ownership_type' => 'Ownership',
                'home_project' => 'Ev layihəsi',
                'current_project' => 'Cari layihə',
                'last_project_geofence' => 'Son layihə geozonası',
                'exited_at' => 'Son layihə geozonasından çıxış',
                'last_confirmed_at' => 'Son təsdiqlənmiş vaxt',
                'outside_duration' => 'Layihə geozonalarından kənarda qalma müddəti',
                'last_location' => 'Son məkan',
                'status' => 'Pozuntu statusu',
            ],
            'data' => collect($rows->items())->map(fn ($row): array => [
                'equipment_name' => $row->equipment_name,
                'equipment_type' => $row->equipment_type,
                'ownership_type' => $row->ownership_type,
                'home_project' => $row->project_name ?: 'Məlumatsız',
                'current_project' => $outsideLabel,
                'last_project_geofence' => $row->last_project_geofence ?: 'Məlumatsız',
                'exited_at' => $row->exited_at?->format('Y-m-d H:i'),
                'last_confirmed_at' => $row->last_confirmed_at?->format('Y-m-d H:i'),
                'outside_duration' => $row->duration_label,
                'last_location' => $row->last_location ?: 'Məlumatsız',
                'status' => $row->status_label,
            ])->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
