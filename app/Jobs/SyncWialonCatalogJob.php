<?php

namespace App\Jobs;

use App\Models\WialonCatalogSyncRun;
use App\Services\WialonCatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncWialonCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $runId) {}

    public function handle(WialonCatalogSyncService $sync): void
    {
        $run = WialonCatalogSyncRun::query()->findOrFail($this->runId);

        if (in_array($run->status, [WialonCatalogSyncRun::STATUS_COMPLETED, WialonCatalogSyncRun::STATUS_COMPLETED_WITH_ERRORS, WialonCatalogSyncRun::STATUS_CANCELLED], true)) {
            return;
        }

        $sync->sync($run);
    }
}
