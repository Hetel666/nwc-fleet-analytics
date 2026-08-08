<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeofenceViolationsDashboardRequest;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\XlsxExportService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    public function export(
        GeofenceViolationsDashboardRequest $request,
        GeofenceViolationsDashboardService $dashboard,
        XlsxExportService $xlsx
    ): Response {
        $export = $dashboard->export($request->filters());
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
