<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardLayoutRequest;
use App\Services\DashboardLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardLayoutController extends Controller
{
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

    public function destroy(Request $request, DashboardLayoutService $layout): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $layout->resetToDefault($request->user(), $request->ip());

        return response()->json([
            'message' => 'Dashboard layout reset.',
            'widgets' => $layout->getResolvedLayout(),
        ]);
    }
}
