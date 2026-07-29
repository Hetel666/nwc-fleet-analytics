<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeofenceViolationsDashboardRequest;
use App\Services\GeofenceViolationsDashboardService;
use Illuminate\View\View;

class GeofenceViolationsDashboardController extends Controller
{
    public function __invoke(
        GeofenceViolationsDashboardRequest $request,
        GeofenceViolationsDashboardService $dashboard
    ): View {
        $filters = $request->filters();

        return view('geofence-violations.index', [
            ...$dashboard->getDashboard($filters),
            'filters' => $filters,
            'durationFormatter' => $dashboard->formatDuration(...),
        ]);
    }
}
