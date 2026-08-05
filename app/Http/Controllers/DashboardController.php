<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardDisplayConfigurationService;
use App\Services\DashboardLayoutService;
use App\Services\DashboardService;
use App\Services\DaytimeEfficiencyDashboardService;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\MonthlyEfficiencyDashboardService;
use App\Services\NightDayEfficiencyDashboardService;
use App\Services\NighttimeEfficiencyDashboardService;
use App\Support\DashboardFilterState;
use App\Support\DashboardSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        DashboardService $dashboard,
        DaytimeEfficiencyDashboardService $daytimeEfficiency,
        NighttimeEfficiencyDashboardService $nighttimeEfficiency,
        NightDayEfficiencyDashboardService $nightDayEfficiency,
        MonthlyEfficiencyDashboardService $monthlyEfficiency,
        DashboardLayoutService $layout,
        DashboardDisplayConfigurationService $displayConfiguration,
        GeofenceViolationsDashboardService $geofenceViolations,
        DashboardFilterState $filterState
    ): View {
        return $this->renderDashboard($request, $dashboard, $daytimeEfficiency, $nighttimeEfficiency, $nightDayEfficiency, $monthlyEfficiency, $layout, $displayConfiguration, $geofenceViolations, $filterState);
    }

    public function tab(
        Request $request,
        string $tab,
        DashboardService $dashboard,
        DaytimeEfficiencyDashboardService $daytimeEfficiency,
        NighttimeEfficiencyDashboardService $nighttimeEfficiency,
        NightDayEfficiencyDashboardService $nightDayEfficiency,
        MonthlyEfficiencyDashboardService $monthlyEfficiency,
        DashboardLayoutService $layout,
        DashboardDisplayConfigurationService $displayConfiguration,
        GeofenceViolationsDashboardService $geofenceViolations,
        DashboardFilterState $filterState
    ): View {
        $tabs = config('dashboard.tabs', []);
        $selectedTab = array_key_exists($tab, $tabs) ? $tab : (string) config('dashboard.default_tab', 'overview');

        return $this->renderDashboard($request, $dashboard, $daytimeEfficiency, $nighttimeEfficiency, $nightDayEfficiency, $monthlyEfficiency, $layout, $displayConfiguration, $geofenceViolations, $filterState, $selectedTab, true);
    }

    private function renderDashboard(
        Request $request,
        DashboardService $dashboard,
        DaytimeEfficiencyDashboardService $daytimeEfficiency,
        NighttimeEfficiencyDashboardService $nighttimeEfficiency,
        NightDayEfficiencyDashboardService $nightDayEfficiency,
        MonthlyEfficiencyDashboardService $monthlyEfficiency,
        DashboardLayoutService $layout,
        DashboardDisplayConfigurationService $displayConfiguration,
        GeofenceViolationsDashboardService $geofenceViolations,
        DashboardFilterState $filterState,
        ?string $selectedTab = null,
        bool $fragment = false
    ): View {
        $visibleDashboardTabs = $request->user()?->visibleDashboardTabs() ?? [];
        [$selectedTab, $explicitTabRequest] = DashboardSectionAccess::resolveTabForRequest($request, $selectedTab);

        if (! array_key_exists($selectedTab, $visibleDashboardTabs)) {
            abort_if($explicitTabRequest, 403);

            $selectedTab = array_key_first($visibleDashboardTabs);
            abort_if($selectedTab === null, 403);
        }

        $filterInput = $filterState->filtersForRequest($request);
        $filters = $dashboard->normalizeFilters($filterInput);
        $selectedPeriod = $filterState->selectedPeriod($request, $filterInput);

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
            $nightDayFilters = [
                ...$filters,
                'search' => '',
            ];
            $data['nightDayEfficiencyByOwnership'] = [
                'NWC' => $nightDayEfficiency->summaryForOwnership($nightDayFilters, 'NWC'),
                'ICARE' => $nightDayEfficiency->summaryForOwnership($nightDayFilters, 'ICARE'),
            ];
            $monthlyFilters = [
                ...$filters,
                'search' => '',
            ];
            try {
                $data['monthlyEfficiencyByOwnership'] = [
                    'NWC' => $monthlyEfficiency->summaryForOwnership($monthlyFilters, 'NWC'),
                    'ICARE' => $monthlyEfficiency->summaryForOwnership($monthlyFilters, 'ICARE'),
                ];
            } catch (InvalidArgumentException $exception) {
                $data['monthlyEfficiencyByOwnership'] = [
                    'NWC' => $this->emptyMonthlyEfficiencySummary($exception->getMessage()),
                    'ICARE' => $this->emptyMonthlyEfficiencySummary($exception->getMessage()),
                ];
            }
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
            'dashboardDisplayConfiguration' => $displayConfiguration->getConfiguration(),
            'canManageDashboardLayout' => (bool) $request->user()?->isAdmin(),
            'dashboardTabs' => $visibleDashboardTabs,
            'selectedDashboardTab' => $selectedTab,
            'dashboardSelectedPeriod' => $selectedPeriod,
            'dashboardPreferences' => $dashboardPreferences,
            'dashboardTabFragment' => $fragment,
            'geofenceViolationDashboardWidget' => $selectedTab === 'geozones'
                ? $geofenceViolations->getDashboardWidget($filters)
                : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyMonthlyEfficiencySummary(string $message): array
    {
        return [
            'critical_low' => 0,
            'low' => 0,
            'normal' => 0,
            'total' => 0,
            'total_current_hours' => 0.0,
            'total_normative_hours' => 0.0,
            'efficiency_percent' => 0.0,
            'completeness' => [
                'is_complete' => false,
                'message' => $message,
                'expected_days' => 0,
                'completed_days' => 0,
                'failed_days' => 0,
                'missing_days' => [],
            ],
        ];
    }
}
