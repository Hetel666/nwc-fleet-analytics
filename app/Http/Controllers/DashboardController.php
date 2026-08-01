<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardLayoutService;
use App\Services\DashboardService;
use App\Services\DaytimeEfficiencyDashboardService;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\NighttimeEfficiencyDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        DashboardService $dashboard,
        DaytimeEfficiencyDashboardService $daytimeEfficiency,
        NighttimeEfficiencyDashboardService $nighttimeEfficiency,
        DashboardLayoutService $layout,
        GeofenceViolationsDashboardService $geofenceViolations
    ): View {
        return $this->renderDashboard($request, $dashboard, $daytimeEfficiency, $nighttimeEfficiency, $layout, $geofenceViolations);
    }

    public function tab(
        Request $request,
        string $tab,
        DashboardService $dashboard,
        DaytimeEfficiencyDashboardService $daytimeEfficiency,
        NighttimeEfficiencyDashboardService $nighttimeEfficiency,
        DashboardLayoutService $layout,
        GeofenceViolationsDashboardService $geofenceViolations
    ): View {
        $tabs = config('dashboard.tabs', []);
        $selectedTab = array_key_exists($tab, $tabs) ? $tab : (string) config('dashboard.default_tab', 'overview');

        return $this->renderDashboard($request, $dashboard, $daytimeEfficiency, $nighttimeEfficiency, $layout, $geofenceViolations, $selectedTab, true);
    }

    private function renderDashboard(
        Request $request,
        DashboardService $dashboard,
        DaytimeEfficiencyDashboardService $daytimeEfficiency,
        NighttimeEfficiencyDashboardService $nighttimeEfficiency,
        DashboardLayoutService $layout,
        GeofenceViolationsDashboardService $geofenceViolations,
        ?string $selectedTab = null,
        bool $fragment = false
    ): View {
        $selectedTab ??= array_key_exists((string) $request->query('tab'), config('dashboard.tabs', []))
            ? (string) $request->query('tab')
            : (string) config('dashboard.default_tab', 'overview');
        $filters = $dashboard->normalizeFilters($request->only([
            'date_from',
            'date_to',
            'from',
            'to',
            'project_id',
            'equipment_type_id',
            'ownership_type',
        ]));

        $startedAt = microtime(true);
        $data = $dashboard->getDashboardTab($filters, $selectedTab);

        if ($selectedTab === 'efficiency') {
            $daytimeFilters = [
                ...$filters,
                'search' => '',
            ];
            $data['daytimeEfficiencyByOwnership'] = [
                'NWC' => $daytimeEfficiency->summaryForOwnership($daytimeFilters, 'NWC'),
                'ICARE' => $daytimeEfficiency->summaryForOwnership($daytimeFilters, 'ICARE'),
            ];
            $nighttimeFilters = [
                ...$filters,
                'search' => '',
            ];
            $data['nighttimeEfficiencyByOwnership'] = [
                'NWC' => $nighttimeEfficiency->summaryForOwnership($nighttimeFilters, 'NWC'),
                'ICARE' => $nighttimeEfficiency->summaryForOwnership($nighttimeFilters, 'ICARE'),
            ];
        }
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $dashboardPreferences = $request->user()->resolvedDashboardPreferences();

        Log::info('Dashboard generated', [
            'elapsed_ms' => $elapsedMs,
            'slow' => $elapsedMs >= (int) config('fleet.dashboard.slow_generation_ms', 5000),
            'filters' => $filters,
            'user_id' => $request->user()?->id,
        ]);

        return view('dashboard.index', [
            'data' => $data,
            'filters' => $filters,
            'projects' => Project::query()->where('active', true)->orderBy('name')->get(),
            'equipmentTypeOptions' => EquipmentType::query()->orderBy('name')->get(),
            'selectedProject' => $filters['project_id']
                ? Project::query()->where('active', true)->find($filters['project_id'])
                : null,
            'dashboardLayout' => $layout->getResolvedLayout(),
            'canManageDashboardLayout' => (bool) $request->user()?->isAdmin(),
            'dashboardTabs' => config('dashboard.tabs', []),
            'selectedDashboardTab' => $selectedTab,
            'dashboardPreferences' => $dashboardPreferences,
            'dashboardTabFragment' => $fragment,
            'geofenceViolationDashboardWidget' => $selectedTab === 'geozones'
                ? $geofenceViolations->getDashboardWidget($filters)
                : null,
        ]);
    }
}
