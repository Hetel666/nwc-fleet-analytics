<?php

namespace App\Jobs;

use App\Models\DashboardExport;
use App\Services\DashboardService;
use App\Services\XlsxExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

class GenerateDashboardExportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 2;

    public function __construct(public int $exportId) {}

    public function handle(DashboardService $dashboard, XlsxExportService $xlsx): void
    {
        $record = DashboardExport::query()->findOrFail($this->exportId);
        $record->forceFill([
            'status' => DashboardExport::STATUS_PROCESSING,
            'started_at' => now(config('app.timezone')),
            'error_message' => null,
        ])->save();

        $export = $dashboard->getDashboardExport($record->filters ?? [], $record->block);
        $content = $xlsx->build($export);
        $root = (string) config('fleet.dashboard.export_root', storage_path('app/private/dashboard-exports'));
        $path = $record->id.'.xlsx';
        File::ensureDirectoryExists($root);

        if (File::put($root.DIRECTORY_SEPARATOR.$path, $content) === false) {
            throw new \RuntimeException('Unable to store the generated dashboard export.');
        }

        $record->forceFill([
            'status' => DashboardExport::STATUS_READY,
            'disk' => 'local',
            'path' => $path,
            'file_name' => $export['filename'],
            'completed_at' => now(config('app.timezone')),
            'expires_at' => now(config('app.timezone'))->addHours(
                max(1, (int) config('fleet.dashboard.export_retention_hours', 24))
            ),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        DashboardExport::query()->whereKey($this->exportId)->update([
            'status' => DashboardExport::STATUS_FAILED,
            'error_message' => mb_substr((string) $exception?->getMessage(), 0, 2000),
            'completed_at' => now(config('app.timezone')),
        ]);
    }
}
