<?php

namespace App\Jobs;

use App\Models\DashboardExport;
use App\Services\DashboardService;
use App\Services\XlsxExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateDashboardExportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function __construct(public int $exportId) {}

    public function backoff(): array
    {
        return [120];
    }

    public function handle(DashboardService $dashboard, XlsxExportService $xlsx): void
    {
        $record = DashboardExport::query()->findOrFail($this->exportId);
        $diskName = (string) config('fleet.dashboard.export_disk', 'dashboard_exports');
        $disk = Storage::disk($diskName);

        if ($record->status === DashboardExport::STATUS_READY
            && $record->disk === $diskName
            && filled($record->path)
            && $disk->exists($record->path)) {
            return;
        }

        $path = null;

        try {
            $record->forceFill([
                'status' => DashboardExport::STATUS_PROCESSING,
                'disk' => null,
                'path' => null,
                'file_name' => null,
                'mime_type' => null,
                'file_size' => null,
                'started_at' => now(config('app.timezone')),
                'completed_at' => null,
                'expires_at' => null,
                'error_message' => null,
            ])->save();

            $export = $dashboard->getDashboardExport($record->filters ?? [], $record->block);
            $content = $xlsx->build($export);
            $path = $record->user_id.'/'.Str::uuid().'.xlsx';

            if (! $disk->put($path, $content) || ! $disk->exists($path)) {
                throw new RuntimeException('Unable to store the generated dashboard export.');
            }

            $size = $disk->size($path);

            if ($size <= 0) {
                throw new RuntimeException('Generated dashboard export is empty.');
            }

            $record->forceFill([
                'status' => DashboardExport::STATUS_READY,
                'disk' => $diskName,
                'path' => $path,
                'file_name' => basename((string) $export['filename']),
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'file_size' => $size,
                'completed_at' => now(config('app.timezone')),
                'expires_at' => now(config('app.timezone'))->addHours(
                    max(1, (int) config('fleet.dashboard.export_retention_hours', 24))
                ),
            ])->save();
        } catch (Throwable $exception) {
            if ($path !== null) {
                $disk->delete($path);
            }

            $record->forceFill([
                'status' => DashboardExport::STATUS_FAILED,
                'disk' => null,
                'path' => null,
                'file_name' => null,
                'mime_type' => null,
                'file_size' => null,
                'error_message' => 'Dashboard export generation failed.',
                'completed_at' => null,
                'expires_at' => null,
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DashboardExport::query()->whereKey($this->exportId)->update([
            'status' => DashboardExport::STATUS_FAILED,
            'error_message' => 'Dashboard export generation failed.',
            'completed_at' => null,
        ]);
    }
}
