<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardDrilldownRequest;
use App\Services\DashboardDisplayConfigurationService;
use App\Services\DashboardFleetDrilldownService;
use App\Services\XlsxExportService;
use Symfony\Component\HttpFoundation\Response;

class DashboardDrilldownController extends Controller
{
    public function index(
        DashboardDrilldownRequest $request,
        DashboardFleetDrilldownService $drilldown,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array {
        $filters = $drilldown->filters($request->validated());
        $this->guardVisibleEfficiencyStatus($filters, $displayConfiguration);
        $units = $drilldown->getUnits($filters);

        return [
            'title' => $drilldown->title($filters),
            'filters' => $drilldown->filterSummary($filters),
            'summary' => [
                'total' => $units->total(),
                ...$drilldown->resultSummary($filters),
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
        DashboardDisplayConfigurationService $displayConfiguration,
        XlsxExportService $xlsx
    ): Response {
        $filters = $drilldown->filters($request->validated());
        $this->guardVisibleEfficiencyStatus($filters, $displayConfiguration);
        $export = $drilldown->export($filters);
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }

    private function guardVisibleEfficiencyStatus(array $filters, DashboardDisplayConfigurationService $displayConfiguration): void
    {
        $status = $filters['work_category'] ?? $filters['day_status'] ?? null;

        abort_unless($displayConfiguration->isStatusVisible('general_efficiency', $status), 403, 'Dashboard status is hidden.');
    }
}
