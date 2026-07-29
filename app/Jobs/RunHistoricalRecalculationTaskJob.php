<?php

namespace App\Jobs;

use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Services\HistoricalRecalculationService;
use App\Services\WialonReportStatsSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class RunHistoricalRecalculationTaskJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout;

    public int $tries;

    public function __construct(public int $taskId)
    {
        $this->timeout = (int) config('historical_recalculation.timeout', 900);
        $this->tries = max(1, (int) config('historical_recalculation.tries', 3));
    }

    public function handle(WialonReportStatsSyncService $sync, HistoricalRecalculationService $service): void
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

            $equipmentCount = $this->runSectionFetch($run, $task, $sync);

            $service->markFetchTaskCompleted($task, $equipmentCount);
        } catch (Throwable $exception) {
            $service->markTaskFailed($task, $exception->getMessage());
        } finally {
            optional($lock)->release();
            optional($runLock)->release();
            $service->dispatchNextPendingFetchTask($run->refresh());
        }
    }

    private function runSectionFetch(
        HistoricalRecalculation $run,
        HistoricalRecalculationTask $task,
        WialonReportStatsSyncService $sync
    ): int {
        $date = $task->stat_date->toDateString();

        if ($run->dashboard_section === HistoricalRecalculation::SECTION_TOP_WORKING_UNITS) {
            $this->runArtisanOrFail('fleet:sync-engine-hours-report', array_filter([
                '--date' => $date,
                '--project' => $task->project_id,
                '--ownership' => $task->ownership_type,
                '--force' => (bool) $run->force,
                '--limit' => 50,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            return 0;
        }

        if ($run->dashboard_section === HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE) {
            $this->runArtisanOrFail('fleet:sync-geozon-api', array_filter([
                '--from' => $date.' 00:00:00',
                '--to' => $date.' 23:59:59',
                '--project' => $task->project_id,
                '--force' => (bool) $run->force,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            return 0;
        }

        if ($run->dashboard_section === HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS) {
            $this->runArtisanOrFail('fleet:sync-geofence-violations-report', array_filter([
                '--from' => $date.' 00:00:00',
                '--to' => $date.' 23:59:59',
                '--project' => $task->project_id,
                '--force' => (bool) $run->force,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            return 0;
        }

        $result = $sync->syncDailyEngineHoursReport([
            'date_from' => $date,
            'date_to' => $date,
            'project_id' => $task->project_id,
            'ownership_type' => $task->ownership_type,
        ], (bool) $run->force);

        return (int) ($result['equipment_count'] ?? 0);
    }

    private function runArtisanOrFail(string $command, array $parameters): void
    {
        $exitCode = Artisan::call($command, $parameters);

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());
            $message = "Command {$command} failed with exit code {$exitCode}.";

            if ($output !== '') {
                $message .= ' '.$output;
            }

            throw new RuntimeException($message);
        }
    }
}
