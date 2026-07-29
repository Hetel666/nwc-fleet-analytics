<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardLayoutService;
use App\Services\DashboardService;
use App\Services\GeofenceViolationsDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ProjectDashboardController extends Controller
{
    public function show(
        Request $request,
        Project $project,
        DashboardService $dashboard,
        DashboardLayoutService $layout,
        GeofenceViolationsDashboardService $geofenceViolations
    ): View {
        $selectedTab = array_key_exists((string) $request->query('tab'), config('dashboard.tabs', []))
            ? (string) $request->query('tab')
            : (string) config('dashboard.default_tab', 'overview');
        $filters = $dashboard->normalizeFilters([
            ...$request->only([
                'date_from',
                'date_to',
                'from',
                'to',
                'equipment_type_id',
                'ownership_type',
            ]),
            'project_id' => $project->id,
        ]);

        $startedAt = microtime(true);
        $data = $dashboard->getDashboardTab($filters, $selectedTab);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('Project dashboard generated', [
            'elapsed_ms' => $elapsedMs,
            'slow' => $elapsedMs >= (int) config('fleet.dashboard.slow_generation_ms', 5000),
            'filters' => $filters,
            'project_id' => $project->id,
            'user_id' => $request->user()?->id,
        ]);

        return view('dashboard.index', [
            'data' => $data,
            'filters' => $filters,
            'projects' => Project::query()->where('active', true)->orderBy('name')->get(),
            'equipmentTypeOptions' => EquipmentType::query()->orderBy('name')->get(),
            'selectedProject' => $project,
            'dashboardLayout' => $layout->getResolvedLayout(),
            'canManageDashboardLayout' => (bool) $request->user()?->isAdmin(),
            'dashboardTabs' => config('dashboard.tabs', []),
            'selectedDashboardTab' => $selectedTab,
            'dashboardTabFragment' => false,
            'geofenceViolationDashboardWidget' => $selectedTab === 'geozones'
                ? $geofenceViolations->getDashboardWidget($filters)
                : null,
        ]);
    }
}
