<?php

namespace App\Services;

use App\Jobs\FinalizeHistoricalRecalculationJob;
use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DashboardReportPipelineService
{
    private const STORE_KEY = 'dashboard_report_pipelines';

    private const LOCK_KEY = 'dashboard-report-pipeline';

    private const STATUS_PENDING = 'pending';

    private const STATUS_RUNNING = 'running';

    private const STATUS_COMPLETED = 'completed';

    private const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    private const STATUS_FAILED = 'failed';

    /** @param  array<int, array<string, mixed>>  $plans */
    public function queue(
        array $plans,
        string $source,
        ?int $priority = null,
        bool $allowDuplicate = false,
        ?User $user = null
    ): array
    {
        $plans = $this->normalizePlans($plans);

        if ($plans === []) {
            return ['status' => 'empty', 'pipeline' => null, 'started_run_id' => null];
        }

        return $this->withLock(function () use ($plans, $source, $priority, $allowDuplicate, $user): array {
            $pipelines = $this->load();
            $this->reconcile($pipelines);
            $signature = $this->signature($plans, $source);

            if (! $allowDuplicate) {
                foreach ($pipelines as $existing) {
                    if (($existing['signature'] ?? null) === $signature
                        && in_array($existing['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                        $result = $this->continueNext($pipelines);
                        $this->save($pipelines);

                        return [
                            'status' => 'existing',
                            'pipeline' => $this->pipelineById($pipelines, (string) $existing['id']),
                            'started_run_id' => $result['started_run_id'],
                        ];
                    }
                }
            }

            $now = now(config('app.timezone'))->toDateTimeString();
            $pipeline = [
                'id' => (string) Str::uuid(),
                'signature' => $signature,
                'source' => $source,
                'priority' => $priority ?? $this->priorityForSource($source),
                'status' => self::STATUS_PENDING,
                'plans' => $plans,
                'current_index' => 0,
                'current_run_id' => null,
                'requested_by' => $user?->id,
                'run_ids' => [],
                'steps' => [],
                'errors' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $pipelines[] = $pipeline;
            $this->pruneCompleted($pipelines);
            $result = $this->continueNext($pipelines);
            $this->save($pipelines);

            return [
                'status' => 'queued',
                'pipeline' => $this->pipelineById($pipelines, (string) $pipeline['id']),
                'started_run_id' => $result['started_run_id'],
            ];
        });
    }

    public function handleRunFinished(HistoricalRecalculation $run): array
    {
        return $this->withLock(function () use ($run): array {
            $pipelines = $this->load();
            $this->reconcile($pipelines, $run);
            $result = $this->continueNext($pipelines);
            $this->save($pipelines);

            return $result;
        });
    }

    public function tick(): array
    {
        return $this->withLock(function (): array {
            $pipelines = $this->load();
            $this->reconcile($pipelines);
            $result = $this->continueNext($pipelines);
            $this->save($pipelines);

            return $result;
        });
    }

    public function hasActivePipeline(): bool
    {
        $pipelines = $this->load();
        $this->reconcile($pipelines);

        foreach ($pipelines as $pipeline) {
            if (in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                return true;
            }
        }

        return false;
    }

    public function containsRun(int $runId): bool
    {
        if ($runId <= 0) {
            return false;
        }

        foreach ($this->load() as $pipeline) {
            if ((int) ($pipeline['current_run_id'] ?? 0) === $runId) {
                return true;
            }

            $runIds = collect($pipeline['run_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->all();

            if (in_array($runId, $runIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function priorityForSource(string $source): int
    {
        return match ($source) {
            'daily', 'nightly' => 100,
            'manual' => 50,
            'historical' => 10,
            default => 25,
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $pipelines = $this->load();
        $this->reconcile($pipelines);

        return $pipelines;
    }

    /** @return array<int, array<string, mixed>> */
    public function queueSnapshot(): array
    {
        $pipelines = $this->all();
        $positions = $this->activePipelinePositions($pipelines);
        $jobsByRun = $this->historicalQueueJobsByRun();

        return collect($pipelines)
            ->map(fn (array $pipeline, int $index): array => $this->queueSnapshotRow($pipeline, $index, $positions, $jobsByRun))
            ->values()
            ->all();
    }

    public function clearClosed(): array
    {
        return $this->withLock(function (): array {
            $pipelines = $this->load();
            $this->reconcile($pipelines);

            $kept = [];
            $removed = 0;

            foreach ($pipelines as $pipeline) {
                if (in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                    $kept[] = $pipeline;

                    continue;
                }

                $removed++;
            }

            $this->save($kept);

            return [
                'status' => 'cleared',
                'removed_closed' => $removed,
                'kept_active' => count($kept),
            ];
        });
    }

    /** @param  callable(): array  $callback */
    private function withLock(callable $callback): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 30);

        if (! $lock->get()) {
            return ['status' => 'locked', 'pipeline' => null, 'started_run_id' => null];
        }

        try {
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function activePipelinePositions(array $pipelines): array
    {
        $active = [];

        foreach ($pipelines as $index => $pipeline) {
            if (! in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                continue;
            }

            $active[] = [
                'index' => $index,
                'priority' => (int) ($pipeline['priority'] ?? 0),
                'created_at' => (string) ($pipeline['created_at'] ?? ''),
            ];
        }

        usort($active, function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: strcmp($a['created_at'], $b['created_at']);
        });

        $positions = [];

        foreach ($active as $position => $entry) {
            $positions[(int) $entry['index']] = $position + 1;
        }

        return $positions;
    }

    /**
     * @param  array<string, mixed>  $pipeline
     * @param  int  $pipelineIndex
     * @param  array<int, int>  $positions
     * @param  array<int, array<string, mixed>>  $jobsByRun
     * @return array<string, mixed>
     */
    private function queueSnapshotRow(array $pipeline, int $pipelineIndex, array $positions, array $jobsByRun): array
    {
        $plans = collect($pipeline['plans'] ?? [])
            ->filter(fn (mixed $plan): bool => is_array($plan))
            ->values();
        $planCount = $plans->count();
        $currentIndex = max(0, min((int) ($pipeline['current_index'] ?? 0), max(0, $planCount - 1)));
        $plan = $plans->get($currentIndex, []);
        $runId = (int) ($pipeline['current_run_id'] ?? 0);
        $run = $runId > 0
            ? HistoricalRecalculation::query()->find($runId)
            : $this->latestPipelineRun($pipeline);
        $job = $run instanceof HistoricalRecalculation ? ($jobsByRun[(int) $run->id] ?? null) : null;
        $step = $pipeline['steps'][$currentIndex] ?? [];
        $createdAt = (string) ($job['created_at'] ?? ($pipeline['created_at'] ?? ''));
        $startedAt = (string) ($job['reserved_at'] ?? ($step['started_at'] ?? optional($run?->started_at)->toDateTimeString() ?? ''));
        $status = $this->pipelineDisplayStatus($pipeline, $run, $job);
        [$progressDone, $progressTotal] = $this->pipelineProgress($pipeline, $run, $currentIndex, $planCount);
        $errors = collect($pipeline['errors'] ?? [])
            ->filter()
            ->values();
        $lastTaskError = $run instanceof HistoricalRecalculation
            ? HistoricalRecalculationTask::query()
                ->where('historical_recalculation_id', $run->id)
                ->whereNotNull('error_message')
                ->latest('updated_at')
                ->value('error_message')
            : null;

        return [
            'queue_id' => $job['id'] ?? (string) ($pipeline['id'] ?? '-'),
            'pipeline_id' => (string) ($pipeline['id'] ?? '-'),
            'created_at' => $createdAt,
            'started_at' => $startedAt !== '' ? $startedAt : null,
            'wait_time' => $this->formatWaitTime($createdAt, $startedAt),
            'status' => $status,
            'section' => $this->sectionLabel((string) ($plan['section'] ?? '')),
            'period' => $this->periodLabel((string) ($plan['date_from'] ?? ''), (string) ($plan['date_to'] ?? '')),
            'scope' => $this->scopeLabel((string) ($plan['scope'] ?? ''), $plan['project_ids'] ?? []),
            'position' => $positions[$pipelineIndex] ?? null,
            'progress' => $progressDone.' / '.$progressTotal,
            'progress_percent' => $progressTotal > 0 ? round(($progressDone / $progressTotal) * 100, 1) : 0,
            'worker' => $this->workerLabel($job, $run),
            'last_error' => $errors->last() ?: ($lastTaskError ?: null),
            'source' => (string) ($pipeline['source'] ?? '-'),
            'priority' => (int) ($pipeline['priority'] ?? 0),
            'step' => $planCount > 0 ? ($currentIndex + 1).' / '.$planCount : '-',
            'run_id' => $run?->id,
        ];
    }

    /** @param  array<string, mixed>  $pipeline */
    private function latestPipelineRun(array $pipeline): ?HistoricalRecalculation
    {
        $runId = collect($pipeline['run_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->last();

        return $runId ? HistoricalRecalculation::query()->find($runId) : null;
    }

    private function pipelineDisplayStatus(array $pipeline, ?HistoricalRecalculation $run, ?array $job): string
    {
        if ($run instanceof HistoricalRecalculation && ! $run->isTerminal()) {
            if (($job['reserved_at'] ?? null) !== null) {
                return HistoricalRecalculation::STATUS_RUNNING;
            }

            return $run->status === HistoricalRecalculation::STATUS_PENDING ? 'queued' : $run->status;
        }

        return (string) ($pipeline['status'] ?? '-');
    }

    /** @return array{0: int, 1: int} */
    private function pipelineProgress(array $pipeline, ?HistoricalRecalculation $run, int $currentIndex, int $planCount): array
    {
        if ($run instanceof HistoricalRecalculation && (int) $run->total_tasks > 0) {
            return [
                (int) $run->completed_tasks + (int) $run->failed_tasks + (int) $run->cancelled_tasks,
                (int) $run->total_tasks,
            ];
        }

        $metrics = $pipeline['metrics'] ?? [];

        if (is_array($metrics) && (int) ($metrics['total_tasks'] ?? 0) > 0) {
            return [
                (int) ($metrics['completed_tasks'] ?? 0)
                    + (int) ($metrics['failed_tasks'] ?? 0)
                    + (int) ($metrics['cancelled_tasks'] ?? 0),
                (int) $metrics['total_tasks'],
            ];
        }

        return [$currentIndex, max(1, $planCount)];
    }

    private function periodLabel(string $from, string $to): string
    {
        if ($from === '' && $to === '') {
            return '-';
        }

        return $from === $to ? $from : trim($from.' - '.$to, ' -');
    }

    private function scopeLabel(string $scope, mixed $projectIds): string
    {
        $count = collect($projectIds)->count();

        return match ($scope) {
            HistoricalRecalculation::SCOPE_ALL_PROJECTS => 'Bütün layihələr',
            HistoricalRecalculation::SCOPE_SELECTED_PROJECTS => $count > 0 ? 'Seçilmiş layihələr: '.$count : 'Seçilmiş layihələr',
            default => $scope !== '' ? $scope : '-',
        };
    }

    private function sectionLabel(string $section): string
    {
        return match ($section) {
            HistoricalRecalculation::SECTION_DAILY_AVERAGES => 'Orta göstəricilər',
            HistoricalRecalculation::SECTION_EFFICIENCY => 'Effektivlik',
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY => 'Effektivlik gündüz',
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY => 'Effektivlik gecə',
            HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY => 'Gün daxilində gecə effektivliyi',
            HistoricalRecalculation::SECTION_TOP_WORKING_UNITS => 'Top 20',
            HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE => 'Geofence Transferləri',
            HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS => 'Geofence Pozuntuları',
            default => $section !== '' ? $section : '-',
        };
    }

    private function workerLabel(?array $job, ?HistoricalRecalculation $run): string
    {
        if ($job !== null) {
            $state = ($job['reserved_at'] ?? null) !== null ? 'reserved' : 'queued';

            return ($job['queue'] ?? 'historical-recalculations').' / '.$state;
        }

        if ($run instanceof HistoricalRecalculation && ! $run->isTerminal()) {
            return (string) config('historical_recalculation.queue', 'historical-recalculations');
        }

        return '-';
    }

    private function formatWaitTime(string $createdAt, ?string $startedAt): string
    {
        $created = $this->timestamp($createdAt);

        if ($created === null) {
            return '-';
        }

        $end = $startedAt ? $this->timestamp($startedAt) : now(config('app.timezone'))->timestamp;

        if ($end === null) {
            return '-';
        }

        $seconds = max(0, $end - $created);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }

        if ($seconds < 86400) {
            return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
        }

        return floor($seconds / 86400).'d '.floor(($seconds % 86400) / 3600).'h';
    }

    private function timestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /** @return array<int, array<string, mixed>> */
    private function historicalQueueJobsByRun(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $queue = (string) config('historical_recalculation.queue', 'historical-recalculations');
        $jobsByRun = [];

        DB::table('jobs')
            ->where('queue', $queue)
            ->orderBy('id')
            ->get()
            ->each(function (object $queuedJob) use (&$jobsByRun): void {
                $reference = $this->historicalQueueJobReference((string) $queuedJob->payload);
                $runId = $this->queueReferenceRunId($reference);

                if ($runId <= 0) {
                    return;
                }

                $jobsByRun[$runId] = [
                    'id' => (int) $queuedJob->id,
                    'queue' => (string) $queuedJob->queue,
                    'attempts' => (int) $queuedJob->attempts,
                    'reserved_at' => $queuedJob->reserved_at ? date('Y-m-d H:i:s', (int) $queuedJob->reserved_at) : null,
                    'available_at' => $queuedJob->available_at ? date('Y-m-d H:i:s', (int) $queuedJob->available_at) : null,
                    'created_at' => $queuedJob->created_at ? date('Y-m-d H:i:s', (int) $queuedJob->created_at) : null,
                    'reference' => $reference,
                ];
            });

        return $jobsByRun;
    }

    private function queueReferenceRunId(?array $reference): int
    {
        if ($reference === null || ($reference['type'] ?? null) === 'unknown') {
            return 0;
        }

        if (($reference['type'] ?? null) === 'finalize') {
            return (int) ($reference['run_id'] ?? 0);
        }

        $task = HistoricalRecalculationTask::query()->find($reference['task_id'] ?? null);

        return $task instanceof HistoricalRecalculationTask ? (int) $task->historical_recalculation_id : 0;
    }

    private function historicalQueueJobReference(string $payload): ?array
    {
        $decodedPayload = json_decode($payload, true);

        if (! is_array($decodedPayload)) {
            return null;
        }

        $displayName = (string) ($decodedPayload['displayName'] ?? ($decodedPayload['data']['commandName'] ?? ''));
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
            } catch (Throwable) {
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

        if (in_array($displayName, [
            RunHistoricalRecalculationTaskJob::class,
            FinalizeHistoricalRecalculationJob::class,
        ], true)) {
            return [
                'type' => 'unknown',
                'class' => $displayName,
            ];
        }

        return null;
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function continueNext(array &$pipelines): array
    {
        $startedRunId = null;

        if ($this->hasRunningStep($pipelines)) {
            return ['status' => 'waiting', 'started_run_id' => null];
        }

        while (($candidateIndex = $this->nextPipelineIndex($pipelines)) !== null) {
            $pipeline = &$pipelines[$candidateIndex];
            $planIndex = (int) ($pipeline['current_index'] ?? 0);
            $plan = $pipeline['plans'][$planIndex] ?? null;

            if (! is_array($plan)) {
                $this->completePipeline($pipeline);
                unset($pipeline);

                continue;
            }

            try {
                $user = isset($pipeline['requested_by'])
                    ? User::query()->find((int) $pipeline['requested_by'])
                    : null;
                $run = app(HistoricalRecalculationService::class)->createRun($this->payload($plan), $user);
            } catch (ValidationException $exception) {
                $this->markStepFailed($pipeline, $planIndex, collect($exception->errors())->flatten()->implode(' '));
                unset($pipeline);

                continue;
            } catch (Throwable $exception) {
                $this->markStepFailed($pipeline, $planIndex, $exception->getMessage());
                unset($pipeline);

                continue;
            }

            $pipeline['status'] = self::STATUS_RUNNING;
            $pipeline['current_run_id'] = $run->id;
            $pipeline['run_ids'][] = $run->id;
            $pipeline['steps'][$planIndex] = [
                'status' => self::STATUS_RUNNING,
                'run_id' => $run->id,
                'section' => $plan['section'],
                'date_from' => $plan['date_from'],
                'date_to' => $plan['date_to'],
                'started_at' => now(config('app.timezone'))->toDateTimeString(),
            ];
            $pipeline['updated_at'] = now(config('app.timezone'))->toDateTimeString();
            $startedRunId = $run->id;
            unset($pipeline);

            break;
        }

        return ['status' => $startedRunId ? 'started' : 'idle', 'started_run_id' => $startedRunId];
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function reconcile(array &$pipelines, ?HistoricalRecalculation $finishedRun = null): void
    {
        foreach ($pipelines as &$pipeline) {
            if (! in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                continue;
            }

            $runId = (int) ($pipeline['current_run_id'] ?? 0);

            if ($runId <= 0) {
                continue;
            }

            $run = $finishedRun && (int) $finishedRun->id === $runId
                ? $finishedRun->refresh()
                : HistoricalRecalculation::query()->find($runId);

            if (! $run) {
                $this->markCurrentRunMissing($pipeline, $runId);

                continue;
            }

            if (! $run->isTerminal()) {
                continue;
            }

            $planIndex = (int) ($pipeline['current_index'] ?? 0);
            $pipeline['steps'][$planIndex] = array_merge($pipeline['steps'][$planIndex] ?? [], [
                'status' => $run->status,
                'run_id' => $run->id,
                'completed_at' => optional($run->completed_at)->toDateTimeString(),
                'failed_tasks' => (int) $run->failed_tasks,
                'cancelled_tasks' => (int) $run->cancelled_tasks,
            ]);

            if ($run->status !== HistoricalRecalculation::STATUS_COMPLETED) {
                $pipeline['errors'][] = "Run {$run->id} finished as {$run->status}.";
            }

            $pipeline['current_run_id'] = null;
            $pipeline['current_index'] = $planIndex + 1;
            $pipeline['updated_at'] = now(config('app.timezone'))->toDateTimeString();

            if ($pipeline['current_index'] >= count($pipeline['plans'] ?? [])) {
                $this->completePipeline($pipeline);
            }
        }
        unset($pipeline);
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function hasRunningStep(array $pipelines): bool
    {
        foreach ($pipelines as $pipeline) {
            if (! in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                continue;
            }

            $runId = (int) ($pipeline['current_run_id'] ?? 0);

            if ($runId <= 0) {
                continue;
            }

            $run = HistoricalRecalculation::query()->find($runId);

            if ($run && ! $run->isTerminal()) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function nextPipelineIndex(array $pipelines): ?int
    {
        $candidateIndex = null;
        $candidatePriority = PHP_INT_MIN;
        $candidateCreatedAt = null;

        foreach ($pipelines as $index => $pipeline) {
            if (! in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                continue;
            }

            if ((int) ($pipeline['current_run_id'] ?? 0) > 0) {
                continue;
            }

            if ((int) ($pipeline['current_index'] ?? 0) >= count($pipeline['plans'] ?? [])) {
                continue;
            }

            $priority = (int) ($pipeline['priority'] ?? 0);
            $createdAt = (string) ($pipeline['created_at'] ?? '');

            if ($candidateIndex === null
                || $priority > $candidatePriority
                || ($priority === $candidatePriority && $createdAt < (string) $candidateCreatedAt)) {
                $candidateIndex = $index;
                $candidatePriority = $priority;
                $candidateCreatedAt = $createdAt;
            }
        }

        return $candidateIndex;
    }

    /** @param  array<string, mixed>  $pipeline */
    private function markCurrentRunMissing(array &$pipeline, int $runId): void
    {
        $planIndex = (int) ($pipeline['current_index'] ?? 0);
        $pipeline['errors'][] = "Run {$runId} is missing.";
        $pipeline['steps'][$planIndex] = array_merge($pipeline['steps'][$planIndex] ?? [], [
            'status' => self::STATUS_FAILED,
            'run_id' => $runId,
            'completed_at' => now(config('app.timezone'))->toDateTimeString(),
        ]);
        $pipeline['current_run_id'] = null;
        $pipeline['current_index'] = $planIndex + 1;
        $pipeline['updated_at'] = now(config('app.timezone'))->toDateTimeString();

        if ($pipeline['current_index'] >= count($pipeline['plans'] ?? [])) {
            $this->completePipeline($pipeline);
        }
    }

    /** @param  array<string, mixed>  $pipeline */
    private function markStepFailed(array &$pipeline, int $planIndex, string $message): void
    {
        $message = mb_substr($message, 0, 500);
        $pipeline['errors'][] = $message;
        $pipeline['steps'][$planIndex] = array_merge($pipeline['steps'][$planIndex] ?? [], [
            'status' => self::STATUS_FAILED,
            'error' => $message,
            'completed_at' => now(config('app.timezone'))->toDateTimeString(),
        ]);
        $pipeline['current_run_id'] = null;
        $pipeline['current_index'] = $planIndex + 1;
        $pipeline['updated_at'] = now(config('app.timezone'))->toDateTimeString();

        if ($pipeline['current_index'] >= count($pipeline['plans'] ?? [])) {
            $this->completePipeline($pipeline);
        }
    }

    /** @param  array<string, mixed>  $pipeline */
    private function completePipeline(array &$pipeline): void
    {
        $metrics = $this->pipelineMetrics($pipeline);
        $validation = $this->validatePipeline($pipeline, $metrics);

        if (($validation['errors'] ?? []) !== []) {
            $pipeline['errors'] = array_values(array_unique(array_merge(
                $pipeline['errors'] ?? [],
                $validation['errors'],
            )));
        }

        $pipeline['metrics'] = $metrics;
        $pipeline['validation'] = $validation;
        $pipeline['current_run_id'] = null;
        $pipeline['status'] = empty($pipeline['errors'])
            ? self::STATUS_COMPLETED
            : self::STATUS_COMPLETED_WITH_ERRORS;
        $pipeline['completed_at'] = now(config('app.timezone'))->toDateTimeString();
        $pipeline['updated_at'] = now(config('app.timezone'))->toDateTimeString();

        if (empty($pipeline['errors'])) {
            Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
            $pipeline['cache_refreshed_at'] = now(config('app.timezone'))->toDateTimeString();
        }
    }

    /** @param  array<string, mixed>  $pipeline */
    private function pipelineMetrics(array $pipeline): array
    {
        $runIds = collect($pipeline['run_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $runs = HistoricalRecalculation::query()
            ->with('tasks')
            ->whereIn('id', $runIds->all())
            ->get();
        $metrics = [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'failed_tasks' => 0,
            'cancelled_tasks' => 0,
            'processed_objects' => 0,
            'rows_received' => 0,
            'rows_saved' => 0,
            'unmatched_rows' => 0,
            'retry_count' => 0,
            'durations' => [],
        ];

        foreach ($runs as $run) {
            $metrics['total_tasks'] += (int) $run->total_tasks;
            $metrics['completed_tasks'] += (int) $run->completed_tasks;
            $metrics['failed_tasks'] += (int) $run->failed_tasks;
            $metrics['cancelled_tasks'] += (int) $run->cancelled_tasks;
            $metrics['processed_objects'] += (int) $run->processed_objects;
            $metrics['retry_count'] += (int) $run->tasks->sum(fn ($task): int => max(0, (int) $task->attempts - 1));
        }

        $metrics = $this->appendSyncTaskMetrics($metrics, 'efficiency_sync_tasks', [
            'rows_received' => 'report_rows_received',
            'rows_saved' => 'facts_saved_count',
            'unmatched_rows' => 'unmatched_report_rows',
        ], $runIds->all(), 'efficiency_sync_runs');
        $metrics = $this->appendSyncTaskMetrics($metrics, 'daytime_efficiency_sync_tasks', [
            'rows_received' => 'report_rows_received',
            'rows_saved' => 'facts_saved_count',
            'unmatched_rows' => 'unmatched_report_rows',
        ], $runIds->all(), 'daytime_efficiency_sync_runs');
        $metrics = $this->appendSyncTaskMetrics($metrics, 'nighttime_efficiency_sync_tasks', [
            'rows_received' => 'report_rows_received',
            'rows_saved' => 'facts_saved_count',
            'unmatched_rows' => 'unmatched_report_rows',
        ], $runIds->all(), 'nighttime_efficiency_sync_runs');
        $metrics = $this->appendSyncTaskMetrics($metrics, 'night_day_efficiency_sync_tasks', [
            'rows_received' => 'report_rows_received',
            'rows_saved' => 'facts_saved_count',
            'unmatched_rows' => 'unmatched_report_rows',
        ], $runIds->all(), 'night_day_efficiency_sync_runs');

        foreach ($pipeline['steps'] ?? [] as $index => $step) {
            $started = isset($step['started_at']) ? strtotime((string) $step['started_at']) : false;
            $completed = isset($step['completed_at']) ? strtotime((string) $step['completed_at']) : false;

            $metrics['durations'][] = [
                'index' => (int) $index,
                'section' => (string) ($step['section'] ?? ''),
                'seconds' => $started !== false && $completed !== false ? max(0, $completed - $started) : null,
            ];
        }

        if ((int) $metrics['rows_saved'] === 0) {
            $metrics['rows_saved'] = (int) $metrics['processed_objects'];
        }

        return $metrics;
    }

    private function appendSyncTaskMetrics(array $metrics, string $taskTable, array $columns, array $historicalRunIds, string $runTable): array
    {
        if ($historicalRunIds === [] || ! Schema::hasTable($taskTable) || ! Schema::hasTable($runTable)) {
            return $metrics;
        }

        $availableColumns = collect(Schema::getColumnListing($taskTable));
        $selects = [];

        foreach ($columns as $metric => $column) {
            if ($availableColumns->contains($column)) {
                $selects[$metric] = $column;
            }
        }

        if ($selects === []) {
            return $metrics;
        }

        $query = DB::table($taskTable)
            ->join($runTable, $runTable.'.id', '=', $taskTable.'.run_id')
            ->whereIn($runTable.'.historical_recalculation_id', $historicalRunIds);

        foreach ($selects as $metric => $column) {
            $metrics[$metric] = (int) $metrics[$metric] + (int) (clone $query)->sum($taskTable.'.'.$column);
        }

        return $metrics;
    }

    /** @param  array<string, mixed>  $pipeline */
    private function validatePipeline(array $pipeline, array $metrics): array
    {
        $errors = [];
        $runIds = collect($pipeline['run_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $runs = HistoricalRecalculation::query()->whereIn('id', $runIds->all())->get()->keyBy('id');

        foreach ($runIds as $runId) {
            $run = $runs->get($runId);

            if (! $run) {
                $errors[] = "Run {$runId} is missing during validation.";

                continue;
            }

            if ($run->status !== HistoricalRecalculation::STATUS_COMPLETED) {
                $errors[] = "Run {$run->id} finished as {$run->status}.";
            }

            if ((int) $run->total_tasks <= 0) {
                $errors[] = "Run {$run->id} has no tasks.";
            }

            if ((int) $run->failed_tasks > 0 || (int) $run->cancelled_tasks > 0) {
                $errors[] = "Run {$run->id} has failed or cancelled tasks.";
            }
        }

        if ((int) ($metrics['total_tasks'] ?? 0) > 0
            && (int) ($metrics['completed_tasks'] ?? 0) + (int) ($metrics['failed_tasks'] ?? 0) + (int) ($metrics['cancelled_tasks'] ?? 0) < (int) $metrics['total_tasks']) {
            $errors[] = 'Pipeline has incomplete task accounting.';
        }

        foreach ($this->duplicateFactChecks($pipeline) as $label => $count) {
            if ($count > 0) {
                $errors[] = "{$label} has {$count} duplicate keys.";
            }
        }

        return [
            'status' => $errors === [] ? 'passed' : 'failed',
            'errors' => $errors,
            'checked_at' => now(config('app.timezone'))->toDateTimeString(),
        ];
    }

    /** @param  array<string, mixed>  $pipeline */
    private function duplicateFactChecks(array $pipeline): array
    {
        $dateRanges = collect($pipeline['plans'] ?? [])
            ->map(fn (array $plan): array => [
                'from' => (string) ($plan['date_from'] ?? ''),
                'to' => (string) ($plan['date_to'] ?? ''),
            ])
            ->filter(fn (array $range): bool => $range['from'] !== '' && $range['to'] !== '')
            ->values();

        if ($dateRanges->isEmpty()) {
            return [];
        }

        return [
            'efficiency_daily_facts' => $this->duplicateCount(
                'efficiency_daily_facts',
                'business_date',
                ['business_date', 'project_id', 'wialon_unit_id'],
                $dateRanges->all()
            ),
            'daytime_efficiency_daily_facts' => $this->duplicateCount(
                'daytime_efficiency_daily_facts',
                'business_date',
                ['business_date', 'project_id', 'wialon_unit_id'],
                $dateRanges->all()
            ),
            'nighttime_efficiency_daily_facts' => $this->duplicateCount(
                'nighttime_efficiency_daily_facts',
                'shift_date',
                ['shift_date', 'project_id', 'wialon_unit_id'],
                $dateRanges->all()
            ),
            'night_day_efficiency_daily_facts' => $this->duplicateCount(
                'night_day_efficiency_daily_facts',
                'business_date',
                ['business_date', 'project_id', 'wialon_unit_id'],
                $dateRanges->all()
            ),
            'engine_hours_report_unit_days' => $this->duplicateCount(
                'engine_hours_report_unit_days',
                'stat_date',
                ['stat_date', 'equipment_id'],
                $dateRanges->all()
            ),
        ];
    }

    private function duplicateCount(string $table, string $dateColumn, array $keys, array $dateRanges): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table)
            ->select($keys)
            ->selectRaw('COUNT(*) as total')
            ->where(function ($query) use ($dateColumn, $dateRanges): void {
                foreach ($dateRanges as $range) {
                    $query->orWhereBetween($dateColumn, [$range['from'], $range['to']]);
                }
            })
            ->groupBy($keys)
            ->havingRaw('COUNT(*) > 1');

        return DB::query()->fromSub($query, 'duplicates')->count();
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function pruneCompleted(array &$pipelines): void
    {
        $completed = [];
        $active = [];

        foreach ($pipelines as $pipeline) {
            if (in_array($pipeline['status'] ?? null, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                $active[] = $pipeline;
            } else {
                $completed[] = $pipeline;
            }
        }

        usort($completed, fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        $pipelines = array_merge($active, array_slice($completed, 0, 20));
    }

    /** @return array<int, array<string, mixed>> */
    private function load(): array
    {
        $raw = Setting::query()->where('key', self::STORE_KEY)->value('value');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function save(array $pipelines): void
    {
        Setting::updateOrCreate(
            ['key' => self::STORE_KEY],
            [
                'value' => json_encode(array_values($pipelines), JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
            ]
        );
    }

    /** @param  array<int, array<string, mixed>>  $pipelines */
    private function pipelineById(array $pipelines, string $id): ?array
    {
        foreach ($pipelines as $pipeline) {
            if (($pipeline['id'] ?? null) === $id) {
                return $pipeline;
            }
        }

        return null;
    }

    /** @param  array<int, array<string, mixed>>  $plans */
    private function signature(array $plans, string $source): string
    {
        return sha1(json_encode([
            'source' => $source,
            'plans' => $plans,
        ]));
    }

    /** @param  array<int, array<string, mixed>>  $plans */
    private function normalizePlans(array $plans): array
    {
        return collect($plans)
            ->filter(fn (mixed $plan): bool => is_array($plan))
            ->map(function (array $plan): array {
                $operation = (string) ($plan['operation'] ?? HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE);

                if (! in_array($operation, [
                    HistoricalRecalculation::OPERATION_FETCH,
                    HistoricalRecalculation::OPERATION_RECALCULATE,
                    HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                ], true)) {
                    $operation = HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE;
                }

                return [
                    'section' => (string) ($plan['section'] ?? $plan['dashboard_section'] ?? ''),
                    'date_from' => (string) ($plan['date_from'] ?? ''),
                    'date_to' => (string) ($plan['date_to'] ?? ''),
                    'timezone' => (string) ($plan['timezone'] ?? config('historical_recalculation.timezone', config('app.timezone', 'Asia/Baku'))),
                    'operation' => $operation,
                    'scope' => (string) ($plan['scope'] ?? HistoricalRecalculation::SCOPE_ALL_PROJECTS),
                    'project_ids' => collect($plan['project_ids'] ?? [])
                        ->map(fn (mixed $id): int => (int) $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'force' => (bool) ($plan['force'] ?? false),
                    'options' => [
                        'vehicle_types' => collect($plan['vehicle_types'] ?? [])
                            ->map(fn (mixed $type): string => trim((string) $type))
                            ->filter()
                            ->unique()
                            ->values()
                            ->all(),
                        'monthly_efficiency_source' => $this->canonicalMonthlyEfficiencySource($plan['monthly_efficiency_source'] ?? null),
                    ],
                ];
            })
            ->filter(fn (array $plan): bool => $plan['section'] !== '' && $plan['date_from'] !== '' && $plan['date_to'] !== '')
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $plan */
    private function payload(array $plan): array
    {
        return [
            'date_from' => $plan['date_from'],
            'date_to' => $plan['date_to'],
            'timezone' => $plan['timezone'],
            'dashboard_section' => $plan['section'],
            'operation' => $plan['operation'],
            'scope' => $plan['scope'],
            'project_ids' => $plan['project_ids'],
            'force' => (bool) $plan['force'],
            'options' => $plan['options'],
        ];
    }

    private function canonicalMonthlyEfficiencySource(mixed $source): ?string
    {
        $source = strtolower(trim((string) $source));

        return in_array($source, ['group_report', 'date_report'], true) ? $source : null;
    }
}
