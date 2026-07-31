<?php

namespace App\Http\Controllers;

use App\Http\Requests\DaytimeEfficiencyDashboardRequest;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DaytimeEfficiencyDashboardService;
use Illuminate\View\View;

final class DaytimeEfficiencyDashboardController extends Controller
{
    public function __invoke(
        DaytimeEfficiencyDashboardRequest $request,
        DaytimeEfficiencyDashboardService $dashboard
    ): View {
        abort_unless(config('daytime_efficiency.enabled', true), 404);
        $filters = $request->validated();

        return view('daytime-efficiency.index', [
            'filters' => $filters,
            'data' => $dashboard->data($filters),
            'projects' => Project::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'equipmentTypes' => EquipmentType::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
