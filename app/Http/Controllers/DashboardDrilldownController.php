<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardDrilldownRequest;
use App\Services\DashboardFleetDrilldownService;
use App\Services\XlsxExportService;
use Symfony\Component\HttpFoundation\Response;

class DashboardDrilldownController extends Controller
{
    public function index(DashboardDrilldownRequest $request, DashboardFleetDrilldownService $drilldown): array
    {
        $filters = $drilldown->filters($request->validated());
        $units = $drilldown->getUnits($filters);

        return [
            'title' => $drilldown->title($filters),
            'filters' => $drilldown->filterSummary($filters),
            'summary' => [
                'total' => $units->total(),
            ],
            'columns' => $drilldown->columns($filters),
            'data' => $units->items(),
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
            ],
        ];
    }

    public function export(
        DashboardDrilldownRequest $request,
        DashboardFleetDrilldownService $drilldown,
        XlsxExportService $xlsx
    ): Response {
        $filters = $drilldown->filters($request->validated());
        $export = $drilldown->export($filters);
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
