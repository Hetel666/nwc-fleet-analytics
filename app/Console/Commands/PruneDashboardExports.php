<?php

namespace App\Console\Commands;

use App\Models\DashboardExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneDashboardExports extends Command
{
    protected $signature = 'fleet:prune-dashboard-exports';

    protected $description = 'Delete expired generated dashboard export files.';

    public function handle(): int
    {
        $recordsPruned = 0;
        $orphansPruned = 0;
        $defaultDisk = (string) config('fleet.dashboard.export_disk', 'dashboard_exports');
        $now = now(config('app.timezone'));

        DashboardExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->whereNotIn('status', [DashboardExport::STATUS_PENDING, DashboardExport::STATUS_PROCESSING])
            ->orderBy('id')
            ->chunkById(200, function ($records) use (&$recordsPruned, $defaultDisk): void {
                foreach ($records as $record) {
                    if ($record->path) {
                        Storage::disk($record->disk ?: $defaultDisk)->delete($record->path);
                    }

                    $record->delete();
                    $recordsPruned++;
                }
            });

        DashboardExport::query()
            ->where('status', DashboardExport::STATUS_READY)
            ->whereNotNull('path')
            ->orderBy('id')
            ->chunkById(200, function ($records) use (&$recordsPruned, $defaultDisk): void {
                foreach ($records as $record) {
                    if (! Storage::disk($record->disk ?: $defaultDisk)->exists($record->path)) {
                        $record->delete();
                        $recordsPruned++;
                    }
                }
            });

        $knownPaths = DashboardExport::query()
            ->where(function ($query) use ($defaultDisk): void {
                $query->where('disk', $defaultDisk)->orWhereNull('disk');
            })
            ->whereNotNull('path')
            ->pluck('path')
            ->flip();
        $orphanCutoff = $now->copy()->subHours(
            max(1, (int) config('fleet.dashboard.export_retention_hours', 24))
        )->getTimestamp();
        $disk = Storage::disk($defaultDisk);

        foreach ($disk->allFiles() as $path) {
            if ($knownPaths->has($path)) {
                continue;
            }

            try {
                if ($disk->lastModified($path) <= $orphanCutoff && $disk->delete($path)) {
                    $orphansPruned++;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $this->info("Pruned {$recordsPruned} dashboard export records and {$orphansPruned} orphan files.");

        return self::SUCCESS;
    }
}
