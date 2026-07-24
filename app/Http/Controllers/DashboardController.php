<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardLayoutService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $dashboard, DashboardLayoutService $layout): View
    {
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
        $data = $dashboard->getDashboard($filters);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

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
            'selectedProject' => null,
            'dashboardLayout' => $layout->getResolvedLayout(),
            'dashboardDefaultLayout' => $layout->getDefaultLayout(),
        ]);
    }
}
