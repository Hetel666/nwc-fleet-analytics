<?php

namespace App\Services;

use App\Models\HistoricalRecalculation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
        $pipeline['current_run_id'] = null;
        $pipeline['status'] = empty($pipeline['errors'])
            ? self::STATUS_COMPLETED
            : self::STATUS_COMPLETED_WITH_ERRORS;
        $pipeline['completed_at'] = now(config('app.timezone'))->toDateTimeString();
        $pipeline['updated_at'] = now(config('app.timezone'))->toDateTimeString();
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
        ];
    }
}
