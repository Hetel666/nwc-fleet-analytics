<?php

namespace App\Console\Commands;

use App\Models\DashboardExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneDashboardExports extends Command
{
    protected $signature = 'fleet:prune-dashboard-exports';

    protected $description = 'Delete expired generated dashboard export files.';

    public function handle(): int
    {
        $count = 0;
        $root = (string) config('fleet.dashboard.export_root', storage_path('app/private/dashboard-exports'));

        DashboardExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now(config('app.timezone')))
            ->orderBy('id')
            ->chunkById(200, function ($records) use (&$count, $root): void {
                foreach ($records as $record) {
                    if ($record->path) {
                        File::delete($root.DIRECTORY_SEPARATOR.$record->path);
                    }

                    $record->delete();
                    $count++;
                }
            });

        $this->info("Pruned {$count} dashboard exports.");

        return self::SUCCESS;
    }
}
