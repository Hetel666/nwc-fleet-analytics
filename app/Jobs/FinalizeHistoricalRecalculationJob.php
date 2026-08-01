<?php

namespace App\Jobs;

use App\Services\HistoricalRecalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FinalizeHistoricalRecalculationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(HistoricalRecalculationService $service): void
    {
        $lock = Cache::lock('historical-recalculation-finalize:'.$this->runId, (int) config('historical_recalculation.lock_seconds', 7200));

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $service->finalize($this->runId);
        } finally {
            optional($lock)->release();
        }
    }
}
