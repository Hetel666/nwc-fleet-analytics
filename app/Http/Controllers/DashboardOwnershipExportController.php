<?php

namespace App\Http\Controllers;

use App\Services\FleetOwnershipStatsService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardOwnershipExportController extends Controller
{
    public function __invoke(Request $request, FleetOwnershipStatsService $ownershipStats, XlsxExportService $xlsx): Response
    {
        $export = $ownershipStats->export(
            $request->only(['project_id', 'equipment_type_id']),
            $request->query('type')
        );
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
