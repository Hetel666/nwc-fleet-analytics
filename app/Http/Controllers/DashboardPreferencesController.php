<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardPreferencesRequest;
use App\Models\UserDashboardPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->resolvedDashboardPreferences());
    }

    public function update(UpdateDashboardPreferencesRequest $request): JsonResponse
    {
        if (! Schema::hasTable('user_dashboard_preferences')) {
            return response()->json(UserDashboardPreference::defaults());
        }

        $settings = array_replace(
            $request->user()->resolvedDashboardPreferences(),
            $request->validated(),
        );

        $preference = UserDashboardPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $this->persistableSettings($settings),
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

    /** @param  array<string, mixed>  $settings */
    private function persistableSettings(array $settings): array
    {
        if (! Schema::hasTable('user_dashboard_preferences')) {
            return [];
        }

        return collect($settings)
            ->filter(fn (mixed $value, string $key): bool => Schema::hasColumn('user_dashboard_preferences', $key))
            ->all();
    }
}
