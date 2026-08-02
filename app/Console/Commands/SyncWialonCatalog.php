<?php

namespace App\Console\Commands;

use App\Models\WialonCatalogSyncRun;
use App\Services\WialonCatalogSyncService;
use Illuminate\Console\Command;

class SyncWialonCatalog extends Command
{
    protected $signature = 'wialon-catalog:sync
        {--section=* : Limit sync to one or more catalog sections}
        {--now : Execute synchronously in this process instead of only queueing the job}';

    protected $description = 'Queue Wialon catalog synchronization.';

    public function handle(WialonCatalogSyncService $sync): int
    {
        $run = $sync->queue($this->option('section') ?: null, 'console');

        $this->line('Wialon catalog sync queued.');
        $this->line('Run ID: '.$run->id);
        $this->line('Status: '.$run->status);

        if ($this->option('now')) {
            $sync->sync($run->refresh());
            $run->refresh();
            $this->line('Final status: '.$run->status);
        }

        return in_array($run->status, [
            WialonCatalogSyncRun::STATUS_QUEUED,
            WialonCatalogSyncRun::STATUS_RUNNING,
            WialonCatalogSyncRun::STATUS_COMPLETED,
            WialonCatalogSyncRun::STATUS_COMPLETED_WITH_ERRORS,
        ], true) ? self::SUCCESS : self::FAILURE;
    }
}
