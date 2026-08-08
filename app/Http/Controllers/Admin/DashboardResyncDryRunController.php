<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HistoricalRecalculation;
use App\Services\DashboardModuleRegistry;
use App\Services\DashboardResyncDryRunPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardResyncDryRunController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardModuleRegistry $modules,
        DashboardResyncDryRunPlanner $planner
    ): JsonResponse {
        $this->authorize('manage-historical-recalculations');

        $payload = $request->validate([
            'dashboard_code' => ['required', 'string', Rule::in($modules->codes())],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'timezone' => ['nullable', 'timezone'],
            'scope' => ['nullable', Rule::in([
                HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            ])],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        $payload['scope'] ??= HistoricalRecalculation::SCOPE_ALL_PROJECTS;
        $payload['project_ids'] ??= [];
        $payload['force'] = (bool) ($payload['force'] ?? false);

        return response()->json($planner->plan($payload));
    }
}
