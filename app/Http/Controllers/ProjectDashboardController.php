<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardLayoutService;
use App\Services\DashboardService;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\MonthlyEfficiencyDashboardService;
use App\Support\DashboardFilterState;
use App\Support\DashboardSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;

class ProjectDashboardController extends Controller
{
    public function show(
        Request $request,
        Project $project,
        DashboardService $dashboard,
        MonthlyEfficiencyDashboardService $monthlyEfficiency,
        DashboardLayoutService $layout,
        GeofenceViolationsDashboardService $geofenceViolations,
        DashboardFilterState $filterState
    ): View {
        $visibleDashboardTabs = $request->user()?->visibleDashboardTabs() ?? [];
        [$selectedTab, $explicitTabRequest] = DashboardSectionAccess::resolveTabForRequest($request);

        if (! array_key_exists($selectedTab, $visibleDashboardTabs)) {
            abort_if($explicitTabRequest, 403);

            $selectedTab = array_key_first($visibleDashboardTabs);
            abort_if($selectedTab === null, 403);
        }

        $filterInput = $filterState->filtersForRequest($request, [
            'project_id' => $project->id,
        ]);
        $filters = $dashboard->normalizeFilters($filterInput);
        $selectedPeriod = $filterState->selectedPeriod($request, $filterInput);

        $startedAt = microtime(true);
        $data = $dashboard->getDashboardTab($filters, $selectedTab);

        if ($selectedTab === 'efficiency') {
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
            'projects' => Project::query()
                ->where('active', true)
                ->excludeFromOperationalDashboard()
                ->orderBy('name')
                ->get(),
            'equipmentTypeOptions' => EquipmentType::query()->orderBy('name')->get(),
            'selectedProject' => $project,
            'dashboardLayout' => $layout->getResolvedLayout(),
            'canManageDashboardLayout' => (bool) $request->user()?->isAdmin(),
            'dashboardTabs' => $visibleDashboardTabs,
            'selectedDashboardTab' => $selectedTab,
            'dashboardSelectedPeriod' => $selectedPeriod,
            'dashboardTabFragment' => false,
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
