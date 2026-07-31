<?php

namespace App\Http\Controllers;

use App\Http\Requests\DaytimeEfficiencyDashboardRequest;
use App\Services\DaytimeEfficiencyDashboardService;
use App\Services\XlsxExportService;
use Symfony\Component\HttpFoundation\Response;

final class DaytimeEfficiencyExportController extends Controller
{
    public function __invoke(
        DaytimeEfficiencyDashboardRequest $request,
        DaytimeEfficiencyDashboardService $dashboard,
        XlsxExportService $xlsx
    ): Response {
        $export = $dashboard->export($request->validated());
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
