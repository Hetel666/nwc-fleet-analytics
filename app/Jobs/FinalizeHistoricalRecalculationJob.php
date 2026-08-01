<?php

namespace App\Jobs;

use App\Services\HistoricalRecalculationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FinalizeHistoricalRecalculationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 7200;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
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
