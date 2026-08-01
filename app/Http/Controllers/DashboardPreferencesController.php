<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardPreferencesRequest;
use App\Models\UserDashboardPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->resolvedDashboardPreferences());
    }

    public function update(UpdateDashboardPreferencesRequest $request): JsonResponse
    {
        $settings = array_replace(
            $request->user()->resolvedDashboardPreferences(),
            $request->validated(),
        );

        $preference = UserDashboardPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $settings,
        );
        $request->user()->setRelation('dashboardPreference', $preference);

        return response()->json($preference->settings());
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->dashboardPreference()->delete();
        $request->user()->unsetRelation('dashboardPreference');

        return response()->json(UserDashboardPreference::defaults());
    }
}
