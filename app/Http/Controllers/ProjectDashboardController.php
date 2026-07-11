<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectDashboardController extends Controller
{
    public function show(Request $request, Project $project, DashboardService $dashboard): View
    {
        $filters = $dashboard->normalizeFilters([
            ...$request->only(['from', 'to']),
            'project_id' => $project->id,
        ]);

        return view('dashboard.index', [
            'data' => $dashboard->getDashboard($filters),
            'filters' => $filters,
            'projects' => Project::query()->where('active', true)->orderBy('name')->get(),
            'selectedProject' => $project,
        ]);
    }
}
