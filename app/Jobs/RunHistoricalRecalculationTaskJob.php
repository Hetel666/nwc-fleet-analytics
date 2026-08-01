<?php

namespace App\Jobs;

use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Services\HistoricalRecalculationModuleRegistry;
use App\Services\HistoricalRecalculationService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunHistoricalRecalculationTaskJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 7200;

    public function __construct(public int $taskId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->taskId;
    }

    public function handle(
        HistoricalRecalculationModuleRegistry $modules,
        HistoricalRecalculationService $service
    ): void {
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
            $task = $task->refresh();

            if ($task->status === HistoricalRecalculationTask::STATUS_RUNNING) {
                $this->release($this->releaseDelayUntilStale($task));
            }

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

            Log::withContext([
                'run_id' => $run->id,
                'task_id' => $task->id,
                'module' => $run->dashboard_section,
                'project_id' => $task->project_id,
                'business_date' => optional($task->stat_date)->toDateString(),
                'job_uuid' => $this->job?->uuid(),
                'queue' => $this->job?->getQueue(),
            ]);

            $equipmentCount = $modules->execute($run, $task);

            $service->markFetchTaskCompleted($task, $equipmentCount);
        } catch (Throwable $exception) {
            $service->markTaskFailed($task, $exception->getMessage());
        } finally {
            optional($lock)->release();
            optional($runLock)->release();
            $service->dispatchNextPendingFetchTask($run->refresh());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $task = HistoricalRecalculationTask::query()->with('run')->find($this->taskId);

        if (! $task || ! in_array($task->status, [
            HistoricalRecalculationTask::STATUS_PENDING,
            HistoricalRecalculationTask::STATUS_RUNNING,
        ], true)) {
            return;
        }

        $service = app(HistoricalRecalculationService::class);
        $service->markTaskFailed($task, $exception?->getMessage() ?: 'Queue worker failed before task completion.');
        $service->dispatchNextPendingFetchTask($task->run->refresh());
    }

    private function releaseDelayUntilStale(HistoricalRecalculationTask $task): int
    {
        $staleSeconds = max(5, (int) config('historical_recalculation.stale_running_task_seconds', 2400));

        if (! $task->last_heartbeat_at) {
            return 5;
        }

        $ageSeconds = max(0, now(config('app.timezone'))->getTimestamp() - $task->last_heartbeat_at->getTimestamp());

        return max(5, ($staleSeconds - $ageSeconds) + 5);
    }
}
