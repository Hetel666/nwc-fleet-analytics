<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateDashboardExportJob;
use App\Models\DashboardExport;
use App\Models\Equipment;
use App\Services\DashboardService;
use App\Services\XlsxExportService;
use App\Support\DashboardSectionAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardExportController extends Controller
{
    public function create(Request $request, DashboardService $dashboard, XlsxExportService $xlsx): Response|View
    {
        $filters = $dashboard->normalizeFilters($request->only([
            'date_from',
            'date_to',
            'from',
            'to',
            'project_id',
            'equipment_type_id',
            'ownership_type',
        ]), 'export');
        $block = (string) $request->query('block', 'overview');

        if ($this->shouldRunInBackground($filters)) {
            $record = DashboardExport::query()->create([
                'user_id' => $request->user()->id,
                'block' => $block,
                'filters' => $filters,
                'status' => DashboardExport::STATUS_PENDING,
            ]);

            GenerateDashboardExportJob::dispatch($record->id)->onQueue('default');

            return view('dashboard.exports.pending', ['export' => $record]);
        }

        $startedAt = microtime(true);
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

        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
    }

    public function status(Request $request, DashboardExport $export): array
    {
        $this->authorizeExport($request, $export);
        $expired = $export->expires_at?->isPast() ?? false;

        return [
            'status' => $expired ? DashboardExport::STATUS_FAILED : $export->status,
            'message' => $expired
                ? 'Excel export has expired.'
                : ($export->status === DashboardExport::STATUS_FAILED
                    ? ($export->error_message ?: 'Excel export failed.')
                    : null),
            'download_url' => ! $expired && $export->status === DashboardExport::STATUS_READY
                ? route('dashboard.exports.download', $export)
                : null,
        ];
    }

    public function download(Request $request, DashboardExport $export): StreamedResponse
    {
        $this->authorizeExport($request, $export);
        abort_unless($export->status === DashboardExport::STATUS_READY, 409);
        abort_if($export->expires_at?->isPast() ?? false, 410);
        abort_unless(filled($export->path), 404);

        $diskName = $export->disk ?: (string) config('fleet.dashboard.export_disk', 'dashboard_exports');
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($export->path), 404);

        return $disk->download(
            $export->path,
            $export->file_name ?: 'dashboard.xlsx',
            ['Content-Type' => $export->mime_type ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function shouldRunInBackground(array $filters): bool
    {
        $days = CarbonImmutable::parse($filters['from'])
            ->diffInDays(CarbonImmutable::parse($filters['to'])) + 1;

        if ($days > max(1, (int) config('fleet.dashboard.export_sync_max_days', 31))) {
            return true;
        }

        $equipmentCount = Equipment::query()
            ->where('active', true)
            ->visibleInDashboard()
            ->when($filters['project_id'], fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, int $typeId) => $query->where('equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, string $ownership) => $query->where('ownership_type', $ownership))
            ->count();

        return ($equipmentCount * $days) >= max(
            1,
            (int) config('fleet.dashboard.export_queue_threshold_rows', 5000)
        );
    }

    private function authorizeExport(Request $request, DashboardExport $export): void
    {
        $user = $request->user();

        abort_unless(
            $user?->id === $export->user_id || $user?->isAdmin(),
            403
        );

        abort_unless(
            $user?->canAccessDashboardSection(DashboardSectionAccess::sectionForExportBlock($export->block)),
            403
        );
    }
}
