<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DashboardExportController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): Response
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

        $startedAt = microtime(true);
        $block = (string) $request->query('block', 'overview');
        $export = $dashboard->getDashboardExport($filters, $block);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $rowCount = collect($export['sections'] ?? [])->sum(fn (array $section): int => count($section['rows'] ?? []));

        Log::info('Dashboard export generated', [
            'elapsed_ms' => $elapsedMs,
            'slow' => $elapsedMs >= (int) config('fleet.dashboard.slow_generation_ms', 5000),
            'should_queue' => $rowCount >= (int) config('fleet.dashboard.export_queue_threshold_rows', 5000),
            'row_count' => $rowCount,
            'block' => $block,
            'filters' => $filters,
            'user_id' => $request->user()?->id,
        ]);

        $content = "\xEF\xBB\xBF".view('dashboard.export-excel', ['export' => $export])->render();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
