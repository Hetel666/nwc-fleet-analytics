<?php

namespace App\Services;

use App\Jobs\FinalizeHistoricalRecalculationJob;
use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\Equipment;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HistoricalRecalculationService
{
    public function __construct(private HistoricalRecalculationModuleRegistry $modules) {}

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

        return [
            'days' => $dates->count(),
            'project_groups' => $targets->count(),
            'fetch_tasks' => $fetchTasks,
            'aggregate_tasks' => $aggregateTasks,
            'total_tasks' => $fetchTasks + $aggregateTasks,
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
        $queue = (string) config('historical_recalculation.queue', 'historical-recalculations');
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

        $this->refreshProgress($run);
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

        Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
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
                            'project_id' => (int) $target->project_id,
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

    private function targets(array $payload): Collection
    {
        $projectIds = $this->selectedProjectIds($payload);

        if (in_array(($payload['dashboard_section'] ?? null), [
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
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
            $query = Project::query()
                ->where('active', true)
                ->when($projectIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $projectIds))
                ->whereHas('wialonGroups');

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
        if (in_array($dashboardSection, [
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
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
}
