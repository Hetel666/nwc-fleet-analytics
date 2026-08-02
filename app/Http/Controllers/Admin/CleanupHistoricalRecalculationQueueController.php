<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HistoricalRecalculation;
use App\Services\HistoricalRecalculationService;
use Illuminate\Http\RedirectResponse;

class CleanupHistoricalRecalculationQueueController extends Controller
{
    public function __invoke(
        HistoricalRecalculation $historicalRecalculation,
        HistoricalRecalculationService $service
    ): RedirectResponse {
        $this->authorize('manage-historical-recalculations');

        $summary = $service->cleanupStuckQueue($historicalRecalculation);

        return back()->with('status', sprintf(
            'Historical queue cleanup: %d stale job deleted, %d stale task marked failed, %d active run resumed.',
            $summary['deleted_jobs'],
            $summary['stale_tasks_failed'],
            $summary['active_runs_resumed']
        ));
    }
}
