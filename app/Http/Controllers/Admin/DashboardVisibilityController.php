<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardDisplayConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardVisibilityController extends Controller
{
    public function index(DashboardDisplayConfigurationService $displayConfiguration): View
    {
        return view('admin.dashboard-visibility.index', [
            'configuration' => $displayConfiguration->getConfiguration(),
            'auditRows' => $displayConfiguration->auditRows(),
        ]);
    }

    public function show(DashboardDisplayConfigurationService $displayConfiguration): JsonResponse
    {
        return response()->json([
            ...$displayConfiguration->getConfiguration(),
            'audit' => $displayConfiguration->auditRows(),
        ]);
    }

    public function updateDashboard(
        Request $request,
        string $dashboardCode,
        DashboardDisplayConfigurationService $displayConfiguration
    ): JsonResponse {
        $data = $request->validate([
            'is_visible' => ['required', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json([
            'dashboard' => $displayConfiguration->updateDashboard($dashboardCode, $data, $request->user(), $request->ip()),
            'configuration' => $displayConfiguration->getConfiguration(),
        ]);
    }

    public function statusVisibility(DashboardDisplayConfigurationService $displayConfiguration): JsonResponse
    {
        return response()->json([
            'statuses' => $displayConfiguration->getConfiguration()['statuses'],
        ]);
    }

    public function updateStatus(
        Request $request,
        string $dashboardType,
        string $statusCode,
        DashboardDisplayConfigurationService $displayConfiguration
    ): JsonResponse {
        $data = $request->validate([
            'is_visible' => ['required', 'boolean'],
        ]);

        return response()->json([
            'status' => $displayConfiguration->updateStatus($dashboardType, $statusCode, $data, $request->user(), $request->ip()),
            'configuration' => $displayConfiguration->getConfiguration(),
        ]);
    }

    public function updateOrder(Request $request, DashboardDisplayConfigurationService $displayConfiguration): JsonResponse
    {
        $data = $request->validate([
            'dashboards' => ['required', 'array', 'min:1', 'max:100'],
            'dashboards.*.code' => ['required', 'string', 'max:120'],
            'dashboards.*.display_order' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json([
            'dashboards' => $displayConfiguration->updateOrder($data['dashboards'], $request->user(), $request->ip()),
            'configuration' => $displayConfiguration->getConfiguration(),
        ]);
    }

    public function reset(Request $request, DashboardDisplayConfigurationService $displayConfiguration): JsonResponse
    {
        return response()->json([
            'configuration' => $displayConfiguration->reset($request->user(), $request->ip()),
        ]);
    }

    public function auditLog(Request $request, DashboardDisplayConfigurationService $displayConfiguration): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        return response()->json([
            'data' => $displayConfiguration->auditRows((int) ($data['limit'] ?? 100)),
        ]);
    }
}
