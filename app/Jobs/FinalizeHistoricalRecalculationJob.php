<?php

namespace App\Jobs;

use App\Services\HistoricalRecalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FinalizeHistoricalRecalculationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries;

    public function __construct(public int $runId)
    {
        $this->timeout = (int) config('historical_recalculation.timeout', 900);
        $this->tries = 1;
    }

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
