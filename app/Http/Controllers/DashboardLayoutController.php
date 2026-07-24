<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardLayoutRequest;
use App\Services\DashboardLayoutService;
use Illuminate\Http\JsonResponse;

class DashboardLayoutController extends Controller
{
    public function show(DashboardLayoutService $layout): JsonResponse
    {
        return response()->json([
            'widgets' => $layout->getResolvedLayout(),
        ]);
    }

    public function update(UpdateDashboardLayoutRequest $request, DashboardLayoutService $layout): JsonResponse
    {
        $layout->saveLayout(
            $request->validated('widgets'),
            $request->user(),
            $request->ip()
        );

        return response()->json([
            'message' => 'Dashboard layout saved.',
            'widgets' => $layout->getResolvedLayout(),
        ]);
    }
}
