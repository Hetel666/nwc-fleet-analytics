<?php

namespace App\Jobs;

use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Services\DashboardService;
use App\Services\HistoricalRecalculationService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunHistoricalRecalculationTaskJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout;

    public int $tries;

    public function __construct(public int $taskId)
    {
        $this->timeout = (int) config('historical_recalculation.timeout', 900);
        $this->tries = 1;
    }

    public function handle(DashboardService $dashboard, HistoricalRecalculationService $service): void
    {
        $task = HistoricalRecalculationTask::query()->with('run')->findOrFail($this->taskId);
        $run = $task->run;

        if ($this->batch()?->cancelled() || $run->status === HistoricalRecalculation::STATUS_CANCELLED) {
            $task->forceFill([
                'status' => HistoricalRecalculationTask::STATUS_CANCELLED,
                'completed_at' => now(config('app.timezone')),
            ])->save();
            $service->refreshProgress($run);
            return;
        }

        if ($task->operation !== HistoricalRecalculation::OPERATION_FETCH) {
            return;
        }

        if ($task->status !== HistoricalRecalculationTask::STATUS_PENDING) {
            $service->dispatchNextPendingFetchTask($run);
            return;
        }

        $runLock = Cache::lock(
            'historical-recalculation-run-execution:'.$run->id,
            (int) config('historical_recalculation.lock_seconds', 7200)
        );

        if (! $runLock->get()) {
            $this->release(max(5, (int) config('historical_recalculation.report_task_delay_seconds', 5)));
            return;
        }

        $lock = Cache::lock('historical-recalculation-task:'.$task->id, (int) config('historical_recalculation.lock_seconds', 7200));

        if (! $lock->get()) {
            optional($runLock)->release();
            $this->release(30);
            return;
        }

        try {
            $task = $task->refresh();

            if ($task->status !== HistoricalRecalculationTask::STATUS_PENDING) {
                return;
            }

            $task->forceFill([
                'status' => HistoricalRecalculationTask::STATUS_RUNNING,
                'attempts' => $task->attempts + 1,
                'started_at' => $task->started_at ?: now(config('app.timezone')),
                'last_heartbeat_at' => now(config('app.timezone')),
                'error_message' => null,
            ])->save();

            $run->forceFill([
                'status' => HistoricalRecalculation::STATUS_RUNNING,
                'last_heartbeat_at' => now(config('app.timezone')),
            ])->save();

            $result = $dashboard->syncDailyEngineHoursReport([
                'date_from' => $task->stat_date->toDateString(),
                'date_to' => $task->stat_date->toDateString(),
                'project_id' => $task->project_id,
                'ownership_type' => $task->ownership_type,
            ], (bool) $run->force);

            $service->markFetchTaskCompleted($task, (int) ($result['equipment_count'] ?? 0));
        } catch (Throwable $exception) {
            $service->markTaskFailed($task, $exception->getMessage());
        } finally {
            optional($lock)->release();
            optional($runLock)->release();
            $service->dispatchNextPendingFetchTask($run->refresh());
        }
    }
}
