<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Services\DashboardService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardTopWorkingUnitsExportController extends Controller
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
            'ownership_type',
        ]);

        if ($request->filled('date')) {
            $filters['date_from'] = $request->query('date');
            $filters['date_to'] = $request->query('date');
        }

        if ($request->filled('ownership')) {
            $filters['ownership_type'] = match (strtolower((string) $request->query('ownership'))) {
                'nwc' => Equipment::OWNERSHIP_NWC,
                'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
                default => null,
            };
        }

        if ($request->filled('vehicle_type') && ! $request->filled('equipment_type_id')) {
            $filters['equipment_type_id'] = EquipmentType::query()
                ->where('name', (string) $request->query('vehicle_type'))
                ->value('id');
        }

        $block = strtolower((string) $request->query('ranking')) === 'most'
            ? 'most-working'
            : 'least-working';

        $export = $dashboard->getDashboardExport($filters, $block);
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
