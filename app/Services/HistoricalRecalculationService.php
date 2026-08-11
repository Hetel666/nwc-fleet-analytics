<?php

namespace App\Services;

use App\Jobs\FinalizeHistoricalRecalculationJob;
use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\Equipment;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\NightDayEfficiencySyncRun;
use App\Models\NighttimeEfficiencySyncRun;
use App\Models\Project;
use App\Models\User;
use App\Support\FleetVehicleType;
use App\Support\GeofenceExcludedGroups;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HistoricalRecalculationService
{
    public function __construct(
        private HistoricalRecalculationModuleRegistry $modules,
        private GeofenceExcludedGroups $geofenceExcludedGroups,
        private DashboardResyncDryRunPlanner $dryRunPlanner,
    ) {}

    public function preview(array $payload): array
    {
        $payload['dashboard_section'] = $this->modules->canonicalSection(
            $payload['dashboard_section'] ?? HistoricalRecalculation::SECTION_DAILY_AVERAGES
        );
        $dates = $this->dates($payload['date_from'], $payload['date_to'], $payload['timezone']);
        $targets = $this->targets($payload);
        $aggregateTasks = $this->needsAggregation($payload)
            ? max(1, $this->selectedProjectIds($payload)->count() ?: 1)
            : 0;
        $fetchTasks = $this->needsFetch($payload['operation'], $payload['dashboard_section'] ?? null)
            ? $this->fetchTaskCount($payload, $dates, $targets)
            : 0;

        $preview = [
            'days' => $dates->count(),
            'project_groups' => $targets->count(),
            'fetch_tasks' => $fetchTasks,
            'aggregate_tasks' => $aggregateTasks,
            'total_tasks' => $fetchTasks + $aggregateTasks,
        ];

        return $preview + [
            'dry_run' => $this->dryRunPlanner->plan($payload, $preview),
        ];
    }

    public function createRun(array $payload, ?User $user): HistoricalRecalculation
    {
        $payload = $this->normalizePayload($payload);
        $preview = $this->preview($payload);

        if ($preview['total_tasks'] === 0) {
            throw ValidationException::withMessages([
                'project_ids' => 'Seçilmiş tarix və layihə üçün icra edilə bilən tapşırıq tapılmadı.',
            ]);
        }

        $signature = $this->signature($payload);

        $duplicate = HistoricalRecalculation::query()
            ->where('signature', $signature)
            ->whereIn('status', [HistoricalRecalculation::STATUS_PENDING, HistoricalRecalculation::STATUS_RUNNING])
            ->latest()
            ->first();

        if ($duplicate) {
            $this->dispatch($duplicate);

            return $duplicate->refresh();
        }

        $run = DB::transaction(function () use ($payload, $signature, $user): HistoricalRecalculation {
            $run = HistoricalRecalculation::query()->create([
                'uuid' => (string) Str::uuid(),
                'signature' => $signature,
                'status' => HistoricalRecalculation::STATUS_PENDING,
                'dashboard_section' => $payload['dashboard_section'],
                'operation' => $payload['operation'],
                'scope' => $payload['scope'],
                'date_from' => $payload['date_from'],
                'date_to' => $payload['date_to'],
                'timezone' => $payload['timezone'],
                'force' => $payload['force'],
                'project_ids' => $payload['project_ids'],
                'options_json' => $payload['options'],
                'requested_by' => $user?->id,
                'last_heartbeat_at' => now(config('app.timezone')),
            ]);

            $this->createTasks($run, $payload);

            return $run;
        });

        $this->dispatch($run);

        return $run->refresh();
    }

    public function dispatch(HistoricalRecalculation $run): void
    {
        $run->forceFill([
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(config('app.timezone')),
            'completed_at' => null,
            'last_heartbeat_at' => now(config('app.timezone')),
        ])->save();

        $this->dispatchNextPendingFetchTask($run);
    }

    public function dispatchNextPendingFetchTask(HistoricalRecalculation $run): void
    {
        $queue = $this->queueForRun($run);
        $connection = (string) config('historical_recalculation.connection', 'database');
        $lock = Cache::lock(
            'historical-recalculation-dispatch:'.$run->id,
            (int) config('historical_recalculation.lock_seconds', 7200)
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $run = $run->refresh();

            if ($run->isTerminal()) {
                return;
            }

            if ($run->status === HistoricalRecalculation::STATUS_CANCELLED) {
                return;
            }

            $this->failStaleRunningFetchTasks($run);
            $run = $run->refresh();

            $runningFetchTasks = $run->tasks()
                ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
                ->where('status', HistoricalRecalculationTask::STATUS_RUNNING)
                ->count();

            if ($runningFetchTasks > 0) {
                return;
            }

            $nextTask = $run->tasks()
                ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
                ->where('status', HistoricalRecalculationTask::STATUS_PENDING)
                ->orderBy('stat_date')
                ->orderBy('project_id')
                ->orderBy('ownership_type')
                ->orderBy('id')
                ->first();

            if ($nextTask instanceof HistoricalRecalculationTask) {
                $delaySeconds = max(0, (int) config('historical_recalculation.report_task_delay_seconds', 5));

                RunHistoricalRecalculationTaskJob::dispatch($nextTask->id)
                    ->onConnection($connection)
                    ->onQueue($queue)
                    ->afterCommit()
                    ->delay(now(config('app.timezone'))->addSeconds($delaySeconds));

                return;
            }

            FinalizeHistoricalRecalculationJob::dispatch($run->id)
                ->onConnection($connection)
                ->onQueue($queue)
                ->afterCommit();
        } finally {
            optional($lock)->release();
        }
    }

    public function cleanupStuckQueue(?HistoricalRecalculation $run = null): array
    {
        $queues = $run instanceof HistoricalRecalculation
            ? [$this->queueForRun($run)]
            : $this->historicalQueues();
        $summary = [
            'queue' => implode(',', $queues),
            'deleted_jobs' => 0,
            'kept_jobs' => 0,
            'unknown_jobs' => 0,
            'stale_tasks_failed' => 0,
            'active_runs_checked' => 0,
            'active_runs_resumed' => 0,
            'active_runs_with_queue_job' => 0,
            'deleted_job_ids' => [],
        ];

        $activeRunsQuery = HistoricalRecalculation::query()
            ->whereIn('status', [HistoricalRecalculation::STATUS_PENDING, HistoricalRecalculation::STATUS_RUNNING]);

        if ($run instanceof HistoricalRecalculation) {
            $activeRunsQuery->whereKey($run->id);
        } else {
            $activeRunsQuery->latest('updated_at')->limit(1);
        }

        $activeRuns = $activeRunsQuery->get();

        foreach ($activeRuns as $activeRun) {
            $summary['active_runs_checked']++;
            $summary['stale_tasks_failed'] += $this->failStaleRunningFetchTasks($activeRun);
        }

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->whereIn('queue', $queues)
                ->orderBy('id')
                ->chunkById(100, function ($jobs) use (&$summary): void {
                    foreach ($jobs as $queuedJob) {
                        $reference = $this->historicalQueueJobReference((string) $queuedJob->payload);

                        if ($reference === null) {
                            $summary['kept_jobs']++;

                            continue;
                        }

                        if (($reference['type'] ?? null) === 'unknown') {
                            $summary['unknown_jobs']++;
                            $summary['kept_jobs']++;

                            continue;
                        }

                        $reason = $this->obsoleteHistoricalQueueJobReason($reference);

                        if ($reason === null) {
                            $summary['kept_jobs']++;

                            continue;
                        }

                        $deleted = DB::table('jobs')->where('id', $queuedJob->id)->delete();

                        if ($deleted > 0) {
                            $this->releaseHistoricalQueueUniqueLock($reference);
                            $summary['deleted_jobs']++;
                            $summary['deleted_job_ids'][] = (int) $queuedJob->id;

                            Log::warning('Deleted obsolete historical recalculation queue job.', [
                                'job_id' => (int) $queuedJob->id,
                                'queue' => (string) $queuedJob->queue,
                                'reason' => $reason,
                                'reference' => $reference,
                            ]);
                        }
                    }
                });
        }

        foreach ($activeRuns as $activeRun) {
            $activeRun = $activeRun->refresh();

            if ($activeRun->isTerminal()) {
                continue;
            }

            if ($this->historicalQueueHasActiveJobForRun($activeRun, $this->queueForRun($activeRun))) {
                $summary['active_runs_with_queue_job']++;

                continue;
            }

            $hasPendingFetchTasks = $activeRun->tasks()
                ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
                ->where('status', HistoricalRecalculationTask::STATUS_PENDING)
                ->exists();
            $hasRunningFetchTasks = $activeRun->tasks()
                ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
                ->where('status', HistoricalRecalculationTask::STATUS_RUNNING)
                ->exists();

            if (! $hasPendingFetchTasks && $hasRunningFetchTasks) {
                continue;
            }

            $this->releaseNextHistoricalDispatchUniqueLock($activeRun);
            $this->dispatchNextPendingFetchTask($activeRun);
            $summary['active_runs_resumed']++;
        }

        return $summary;
    }

    public function failStaleRunningFetchTasks(HistoricalRecalculation $run): int
    {
        $staleSeconds = $this->staleRunningTaskSeconds();

        if ($staleSeconds <= 0) {
            return 0;
        }

        $cutoff = now(config('app.timezone'))->subSeconds($staleSeconds);
        $staleTasks = $run->tasks()
            ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
            ->where('status', HistoricalRecalculationTask::STATUS_RUNNING)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->get();

        foreach ($staleTasks as $task) {
            $heartbeat = $task->last_heartbeat_at
                ? $task->last_heartbeat_at->timezone(config('app.timezone'))->toDateTimeString()
                : 'none';

            Log::warning('Historical recalculation task marked failed after stale running heartbeat.', [
                'run_id' => $run->id,
                'task_id' => $task->id,
                'module' => $run->dashboard_section,
                'project_id' => $task->project_id,
                'stat_date' => $task->stat_date?->toDateString(),
                'ownership_type' => $task->ownership_type,
                'last_heartbeat_at' => $heartbeat,
                'stale_seconds' => $staleSeconds,
            ]);

            $this->releaseExecutionLocks($run, [$task]);
            $this->markTaskFailed(
                $task,
                "Stale running task recovered after worker interruption. Last heartbeat: {$heartbeat}; stale threshold: {$staleSeconds} seconds. Review this task before retry."
            );
        }

        return $staleTasks->count();
    }

    /**
     * Release historical execution locks after a verified worker interruption.
     *
     * @param  iterable<HistoricalRecalculationTask>  $tasks
     */
    public function releaseExecutionLocks(HistoricalRecalculation $run, iterable $tasks = []): void
    {
        $lockSeconds = (int) config('historical_recalculation.lock_seconds', 7200);

        Cache::lock('historical-recalculation-run-execution:'.$run->id, $lockSeconds)->forceRelease();

        foreach ($tasks as $task) {
            if ($task instanceof HistoricalRecalculationTask) {
                Cache::lock('historical-recalculation-task:'.$task->id, $lockSeconds)->forceRelease();
            }
        }
    }

    public function cancel(HistoricalRecalculation $run): void
    {
        if ($run->isTerminal()) {
            return;
        }

        if ($run->batch_id && ($batch = Bus::findBatch($run->batch_id))) {
            $batch->cancel();
        }

        $run->tasks()
            ->whereIn('status', [HistoricalRecalculationTask::STATUS_PENDING, HistoricalRecalculationTask::STATUS_RUNNING])
            ->update([
                'status' => HistoricalRecalculationTask::STATUS_CANCELLED,
                'completed_at' => now(config('app.timezone')),
            ]);

        $run->forceFill([
            'status' => HistoricalRecalculation::STATUS_CANCELLED,
            'completed_at' => now(config('app.timezone')),
            'last_heartbeat_at' => now(config('app.timezone')),
        ])->save();

        if ($run->dashboard_section === HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY
            && Schema::hasTable('nighttime_efficiency_sync_runs')) {
            NighttimeEfficiencySyncRun::query()
                ->where('historical_recalculation_id', $run->id)
                ->update([
                    'status' => HistoricalRecalculation::STATUS_CANCELLED,
                    'completed_at' => now(config('app.timezone')),
                ]);
        }

        if ($run->dashboard_section === HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY
            && Schema::hasTable('night_day_efficiency_sync_runs')) {
            NightDayEfficiencySyncRun::query()
                ->where('historical_recalculation_id', $run->id)
                ->update([
                    'status' => HistoricalRecalculation::STATUS_CANCELLED,
                    'completed_at' => now(config('app.timezone')),
                ]);
        }

        $this->refreshProgress($run);
        $this->cleanupStuckQueue($run);
    }

    public function retryFailed(HistoricalRecalculation $run): void
    {
        if ($run->status === HistoricalRecalculation::STATUS_CANCELLED) {
            return;
        }

        $run->tasks()
            ->where('status', HistoricalRecalculationTask::STATUS_FAILED)
            ->update([
                'status' => HistoricalRecalculationTask::STATUS_PENDING,
                'error_message' => null,
                'completed_at' => null,
                'last_heartbeat_at' => null,
            ]);

        $this->dispatch($run->refresh());
    }

    public function markFetchTaskCompleted(HistoricalRecalculationTask $task, int $equipmentCount): void
    {
        $task->forceFill([
            'status' => HistoricalRecalculationTask::STATUS_COMPLETED,
            'equipment_count' => $equipmentCount,
            'completed_at' => now(config('app.timezone')),
            'last_heartbeat_at' => now(config('app.timezone')),
            'error_message' => null,
        ])->save();

        $this->refreshProgress($task->run);
    }

    public function markTaskFailed(HistoricalRecalculationTask $task, string $message): void
    {
        $task->forceFill([
            'status' => HistoricalRecalculationTask::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 4000),
            'completed_at' => now(config('app.timezone')),
            'last_heartbeat_at' => now(config('app.timezone')),
        ])->save();

        $this->refreshProgress($task->run);
    }

    public function markTaskRetryPending(HistoricalRecalculationTask $task, string $message, int $delaySeconds): void
    {
        $task->forceFill([
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'error_message' => mb_substr(
                "Temporary failure; retry scheduled in {$delaySeconds} seconds. {$message}",
                0,
                4000
            ),
            'completed_at' => null,
            'last_heartbeat_at' => now(config('app.timezone')),
        ])->save();

        $this->refreshProgress($task->run);
    }

    public function finalize(int $runId): void
    {
        $run = HistoricalRecalculation::query()->with('tasks')->findOrFail($runId);

        if ($run->status === HistoricalRecalculation::STATUS_CANCELLED) {
            return;
        }

        $incompleteFetchTasks = $run->tasks()
            ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
            ->whereIn('status', [
                HistoricalRecalculationTask::STATUS_PENDING,
                HistoricalRecalculationTask::STATUS_RUNNING,
            ])
            ->count();

        if ($incompleteFetchTasks > 0) {
            $this->refreshProgress($run);
            $this->dispatchNextPendingFetchTask($run->refresh());

            return;
        }

        foreach ($run->tasks->where('operation', HistoricalRecalculation::OPERATION_RECALCULATE) as $task) {
            $this->runAggregateTask($run, $task);
        }

        $this->refreshProgress($run->refresh());

        $failed = (int) $run->tasks()->where('status', HistoricalRecalculationTask::STATUS_FAILED)->count();
        $completed = (int) $run->tasks()->where('status', HistoricalRecalculationTask::STATUS_COMPLETED)->count();
        $total = max(1, (int) $run->tasks()->count());

        $status = $failed === 0
            ? HistoricalRecalculation::STATUS_COMPLETED
            : ($completed > 0 ? HistoricalRecalculation::STATUS_COMPLETED_WITH_ERRORS : HistoricalRecalculation::STATUS_FAILED);

        $run->forceFill([
            'status' => $status,
            'completed_at' => now(config('app.timezone')),
            'last_heartbeat_at' => now(config('app.timezone')),
            'error_summary' => $failed > 0 ? "{$failed} of {$total} tasks failed." : null,
        ])->save();

        $pipelines = app(DashboardReportPipelineService::class);

        if (! $pipelines->containsRun((int) $run->id)) {
            Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
        }

        try {
            $pipelines->handleRunFinished($run->refresh());
        } catch (\Throwable $exception) {
            Log::error('Dashboard report pipeline could not continue after historical run finalization.', [
                'run_id' => $run->id,
                'status' => $run->status,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function refreshProgress(HistoricalRecalculation $run): void
    {
        $counts = $run->tasks()
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(equipment_count), 0) as equipment_count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $run->forceFill([
            'total_tasks' => (int) $run->tasks()->count(),
            'completed_tasks' => (int) ($counts[HistoricalRecalculationTask::STATUS_COMPLETED]->total ?? 0),
            'failed_tasks' => (int) ($counts[HistoricalRecalculationTask::STATUS_FAILED]->total ?? 0),
            'cancelled_tasks' => (int) ($counts[HistoricalRecalculationTask::STATUS_CANCELLED]->total ?? 0),
            'processed_objects' => (int) $run->tasks()->sum('equipment_count'),
            'last_heartbeat_at' => now(config('app.timezone')),
        ])->save();
    }

    private function createTasks(HistoricalRecalculation $run, array $payload): void
    {
        if ($this->needsFetch($payload['operation'], $payload['dashboard_section'] ?? null)) {
            $dates = ($payload['dashboard_section'] ?? null) === HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS
                ? collect([$payload['date_from']])
                : $this->dates($payload['date_from'], $payload['date_to'], $payload['timezone']);

            foreach ($dates as $date) {
                foreach ($this->targets($payload) as $target) {
                    HistoricalRecalculationTask::query()->updateOrCreate(
                        [
                            'historical_recalculation_id' => $run->id,
                            'operation' => HistoricalRecalculation::OPERATION_FETCH,
                            'stat_date' => $date,
                            'project_id' => $target->project_id ? (int) $target->project_id : null,
                            'ownership_type' => $target->ownership_type ?? null,
                        ],
                        ['status' => HistoricalRecalculationTask::STATUS_PENDING]
                    );
                }
            }
        }

        if ($this->needsAggregation($payload)) {
            $projectIds = $this->selectedProjectIds($payload);
            $aggregateScopes = $projectIds->isEmpty() ? collect([null]) : $projectIds;

            foreach ($aggregateScopes as $projectId) {
                HistoricalRecalculationTask::query()->updateOrCreate(
                    [
                        'historical_recalculation_id' => $run->id,
                        'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
                        'stat_date' => null,
                        'project_id' => $projectId,
                        'ownership_type' => null,
                    ],
                    ['status' => HistoricalRecalculationTask::STATUS_PENDING]
                );
            }
        }

        $this->refreshProgress($run);
    }

    private function runAggregateTask(HistoricalRecalculation $run, HistoricalRecalculationTask $task): void
    {
        if ($task->status === HistoricalRecalculationTask::STATUS_COMPLETED) {
            return;
        }

        $task->forceFill([
            'status' => HistoricalRecalculationTask::STATUS_RUNNING,
            'attempts' => $task->attempts + 1,
            'started_at' => $task->started_at ?: now(config('app.timezone')),
            'last_heartbeat_at' => now(config('app.timezone')),
            'error_message' => null,
        ])->save();

        try {
            $options = [
                '--from' => $run->date_from->toDateString(),
                '--to' => $run->date_to->toDateString(),
            ];

            if ($task->project_id) {
                $options['--project'] = $task->project_id;
            } else {
                $options['--all-projects'] = true;
            }

            Artisan::call('fleet:aggregate-statistics', $options);

            $task->forceFill([
                'status' => HistoricalRecalculationTask::STATUS_COMPLETED,
                'completed_at' => now(config('app.timezone')),
                'last_heartbeat_at' => now(config('app.timezone')),
            ])->save();
        } catch (\Throwable $exception) {
            $this->markTaskFailed($task, $exception->getMessage());
        }
    }

    private function normalizePayload(array $payload): array
    {
        $payload['timezone'] = $payload['timezone'] ?? config('historical_recalculation.timezone');
        $payload['dashboard_section'] = $this->modules->canonicalSection(
            $payload['dashboard_section'] ?? HistoricalRecalculation::SECTION_DAILY_AVERAGES
        );
        $payload['date_from'] = Carbon::parse($payload['date_from'], $payload['timezone'])->toDateString();
        $payload['date_to'] = Carbon::parse($payload['date_to'], $payload['timezone'])->toDateString();
        $payload['force'] = (bool) ($payload['force'] ?? false);
        $payload['project_ids'] = $this->selectedProjectIds($payload)->values()->all();
        $payload['options'] = [
            'vehicle_types' => collect($payload['options']['vehicle_types'] ?? $payload['vehicle_types'] ?? [])
                ->map(fn (mixed $type): string => FleetVehicleType::normalize((string) $type))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'monthly_efficiency_source' => $this->canonicalMonthlyEfficiencySource(
                $payload['options']['monthly_efficiency_source'] ?? $payload['monthly_efficiency_source'] ?? null
            ),
        ];

        return $payload;
    }

    private function signature(array $payload): string
    {
        return sha1(json_encode([
            'date_from' => $payload['date_from'],
            'date_to' => $payload['date_to'],
            'timezone' => $payload['timezone'],
            'dashboard_section' => $payload['dashboard_section'],
            'operation' => $payload['operation'],
            'scope' => $payload['scope'],
            'project_ids' => $payload['project_ids'],
            'force' => $payload['force'],
            'options' => $payload['options'],
        ]));
    }

    private function selectedProjectIds(array $payload): Collection
    {
        if (($payload['scope'] ?? null) !== HistoricalRecalculation::SCOPE_SELECTED_PROJECTS) {
            return collect();
        }

        return collect($payload['project_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function canonicalMonthlyEfficiencySource(mixed $source): ?string
    {
        $source = strtolower(trim((string) $source));

        return in_array($source, ['group_report', 'date_report'], true) ? $source : null;
    }

    private function targets(array $payload): Collection
    {
        $projectIds = $this->selectedProjectIds($payload);

        if (($payload['dashboard_section'] ?? null) === HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY) {
            if ($projectIds->isEmpty()) {
                return collect([(object) [
                    'project_id' => null,
                    'ownership_type' => null,
                ]]);
            }

            return Project::query()
                ->where('active', true)
                ->whereIn('id', $projectIds)
                ->whereHas('wialonGroups', fn ($query) => $query
                    ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE]))
                ->get(['id'])
                ->map(fn (Project $project): object => (object) [
                    'project_id' => $project->id,
                    'ownership_type' => null,
                ])
                ->values();
        }

        if (in_array(($payload['dashboard_section'] ?? null), [
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
        ], true)) {
            return Project::query()
                ->where('active', true)
                ->excludeFromOperationalDashboard()
                ->when($projectIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $projectIds))
                ->whereHas('wialonGroups', fn ($query) => $query
                    ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE]))
                ->get(['id'])
                ->map(fn (Project $project): object => (object) [
                    'project_id' => $project->id,
                    'ownership_type' => null,
                ])
                ->values();
        }

        if (in_array(
            $payload['dashboard_section'] ?? HistoricalRecalculation::SECTION_DAILY_AVERAGES,
            [
                HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
            ],
            true
        )) {
            $this->assertSelectedGeofenceProjectsAreAllowed($projectIds);

            $query = Project::query()
                ->where('active', true)
                ->when($projectIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $projectIds))
                ->whereHas('wialonGroups', fn ($query) => $this->geofenceExcludedGroups->applyAllowedProjectWialonGroups($query));

            if (($payload['dashboard_section'] ?? null) === HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS) {
                $query->excludeFromOperationalDashboard();
            }

            return $query
                ->get(['id'])
                ->map(fn (Project $project): object => (object) [
                    'project_id' => $project->id,
                    'ownership_type' => null,
                ])
                ->values();
        }

        return Equipment::query()
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->boundToProjectWialonGroup()
            ->operationalDashboardProject()
            ->when($projectIds->isNotEmpty(), fn ($query) => $query->whereIn('equipments.project_id', $projectIds))
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->get(['equipments.project_id', 'equipments.ownership_type'])
            ->unique(fn (Equipment $equipment): string => $equipment->project_id.'|'.$equipment->ownership_type)
            ->values();
    }

    private function dates(string $from, string $to, string $timezone): Collection
    {
        return collect(CarbonPeriod::create(
            Carbon::parse($from, $timezone)->startOfDay(),
            Carbon::parse($to, $timezone)->startOfDay()
        ))->map(fn (Carbon $date): string => $date->toDateString())->values();
    }

    private function needsFetch(string $operation, ?string $dashboardSection = null): bool
    {
        if ($dashboardSection === HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY) {
            return true;
        }

        if (in_array($dashboardSection, [
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
        ], true)
            && $operation === HistoricalRecalculation::OPERATION_RECALCULATE) {
            return true;
        }

        return in_array($operation, [
            HistoricalRecalculation::OPERATION_FETCH,
            HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
        ], true);
    }

    private function fetchTaskCount(array $payload, Collection $dates, Collection $targets): int
    {
        if (($payload['dashboard_section'] ?? null) === HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS) {
            return $targets->count();
        }

        return $dates->count() * $targets->count();
    }

    private function needsAggregation(array $payload): bool
    {
        if (in_array(($payload['dashboard_section'] ?? HistoricalRecalculation::SECTION_DAILY_AVERAGES), [
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
            HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
            HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
        ], true)) {
            return false;
        }

        return in_array($payload['operation'], [
            HistoricalRecalculation::OPERATION_RECALCULATE,
            HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
        ], true);
    }

    private function assertSelectedGeofenceProjectsAreAllowed(Collection $projectIds): void
    {
        if ($projectIds->isEmpty()) {
            return;
        }

        $excludedProjectIds = $this->geofenceExcludedGroups->projectIdsWithOnlyExcludedGroups();

        if ($excludedProjectIds === []) {
            return;
        }

        if ($projectIds->intersect($excludedProjectIds)->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'project_ids' => GeofenceExcludedGroups::MESSAGE,
        ]);
    }

    private function staleRunningTaskSeconds(): int
    {
        return max(0, (int) config('historical_recalculation.stale_running_task_seconds', 2400));
    }

    public function queueForRun(HistoricalRecalculation $run): string
    {
        return $this->queueForSection((string) $run->dashboard_section);
    }

    public function queueForSection(string $section): string
    {
        $definition = $this->modules->definition($section);

        return (string) ($definition['queue'] ?? config('historical_recalculation.queue', 'historical-recalculations'));
    }

    /** @return array<int, string> */
    public function historicalQueues(): array
    {
        return collect($this->modules->definitions())
            ->pluck('queue')
            ->push((string) config('historical_recalculation.queue', 'historical-recalculations'))
            ->map(fn (mixed $queue): string => trim((string) $queue))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function releaseHistoricalQueueUniqueLock(array $reference): void
    {
        $uniqueLock = new UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class));

        if (($reference['type'] ?? null) === 'task' && isset($reference['task_id'])) {
            $uniqueLock->release(new RunHistoricalRecalculationTaskJob((int) $reference['task_id']));

            return;
        }

        if (($reference['type'] ?? null) === 'finalize' && isset($reference['run_id'])) {
            $uniqueLock->release(new FinalizeHistoricalRecalculationJob((int) $reference['run_id']));
        }
    }

    private function releaseNextHistoricalDispatchUniqueLock(HistoricalRecalculation $run): void
    {
        $nextTask = $run->tasks()
            ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
            ->where('status', HistoricalRecalculationTask::STATUS_PENDING)
            ->orderBy('stat_date')
            ->orderBy('project_id')
            ->orderBy('ownership_type')
            ->orderBy('id')
            ->first();

        $uniqueLock = new UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class));

        if ($nextTask instanceof HistoricalRecalculationTask) {
            $uniqueLock->release(new RunHistoricalRecalculationTaskJob($nextTask->id));

            return;
        }

        $uniqueLock->release(new FinalizeHistoricalRecalculationJob($run->id));
    }

    private function historicalQueueJobReference(string $payload): ?array
    {
        $decodedPayload = json_decode($payload, true);

        if (! is_array($decodedPayload)) {
            return null;
        }

        $displayName = (string) ($decodedPayload['displayName'] ?? ($decodedPayload['data']['commandName'] ?? ''));
        $isHistoricalJob = in_array($displayName, [
            RunHistoricalRecalculationTaskJob::class,
            FinalizeHistoricalRecalculationJob::class,
        ], true);

        $command = $decodedPayload['data']['command'] ?? null;
        $job = null;

        if (is_string($command) && $command !== '') {
            try {
                $job = @unserialize($command, [
                    'allowed_classes' => [
                        RunHistoricalRecalculationTaskJob::class,
                        FinalizeHistoricalRecalculationJob::class,
                    ],
                ]);
            } catch (\Throwable) {
                $job = null;
            }
        }

        if ($job instanceof RunHistoricalRecalculationTaskJob) {
            return [
                'type' => 'task',
                'class' => RunHistoricalRecalculationTaskJob::class,
                'task_id' => $job->taskId,
            ];
        }

        if ($job instanceof FinalizeHistoricalRecalculationJob) {
            return [
                'type' => 'finalize',
                'class' => FinalizeHistoricalRecalculationJob::class,
                'run_id' => $job->runId,
            ];
        }

        if ($isHistoricalJob) {
            return [
                'type' => 'unknown',
                'class' => $displayName,
            ];
        }

        return null;
    }

    private function obsoleteHistoricalQueueJobReason(array $reference): ?string
    {
        if (($reference['type'] ?? null) === 'task') {
            $task = HistoricalRecalculationTask::query()
                ->with('run')
                ->find($reference['task_id'] ?? null);

            if (! $task) {
                return 'missing_task';
            }

            if (! $task->run) {
                return 'missing_run';
            }

            if ($task->run->isTerminal()) {
                return 'terminal_run_'.$task->run->status;
            }

            if (in_array($task->status, [
                HistoricalRecalculationTask::STATUS_CANCELLED,
                HistoricalRecalculationTask::STATUS_COMPLETED,
                HistoricalRecalculationTask::STATUS_FAILED,
            ], true)) {
                return 'task_'.$task->status;
            }

            return null;
        }

        if (($reference['type'] ?? null) === 'finalize') {
            $run = HistoricalRecalculation::query()->find($reference['run_id'] ?? null);

            if (! $run) {
                return 'missing_run';
            }

            if ($run->isTerminal()) {
                return 'terminal_run_'.$run->status;
            }
        }

        return null;
    }

    private function historicalQueueHasActiveJobForRun(HistoricalRecalculation $run, string $queue): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        $hasActiveJob = false;

        DB::table('jobs')
            ->where('queue', $queue)
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($run, &$hasActiveJob): bool {
                foreach ($jobs as $queuedJob) {
                    $reference = $this->historicalQueueJobReference((string) $queuedJob->payload);

                    if (! $this->historicalQueueReferenceBelongsToRun($reference, $run)) {
                        continue;
                    }

                    $hasActiveJob = true;

                    return false;
                }

                return true;
            });

        return $hasActiveJob;
    }

    private function historicalQueueReferenceBelongsToRun(?array $reference, HistoricalRecalculation $run): bool
    {
        if ($reference === null || ($reference['type'] ?? null) === 'unknown') {
            return false;
        }

        if (($reference['type'] ?? null) === 'finalize') {
            return (int) ($reference['run_id'] ?? 0) === (int) $run->id;
        }

        $task = HistoricalRecalculationTask::query()->find($reference['task_id'] ?? null);

        return $task instanceof HistoricalRecalculationTask
            && (int) $task->historical_recalculation_id === (int) $run->id;
    }
}
