<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Services\DashboardService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardOwnershipExportController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, XlsxExportService $xlsx): Response
    {
        $filters = $request->only([
            'date_from',
            'date_to',
            'from',
            'to',
            'project_id',
            'equipment_type_id',
        ]);

        $ownership = mb_strtolower(trim((string) $request->query('ownership', (string) $request->query('type', ''))));
        $filters['ownership_type'] = match (true) {
            $ownership === 'nwc' => Equipment::OWNERSHIP_NWC,
            str_starts_with($ownership, 'icar') => Equipment::OWNERSHIP_ICARE,
            default => null,
        };

        $export = $dashboard->getDashboardExport($filters, 'ownership-share');
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
