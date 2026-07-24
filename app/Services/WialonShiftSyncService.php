<?php

namespace App\Services;

use App\Models\ProjectWialonGroup;
use App\Models\WialonReportSyncItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class WialonShiftSyncService
{
    public const LOCK_KEY = 'wialon-shift-report-sync';

    public function __construct(
        private WialonShiftReportService $reports,
        private WialonShiftReportParser $parser,
        private FleetShiftDailyStatsSyncService $sync,
        private WialonSessionManager $sessions,
    ) {
    }

    /**
     * @param array{from?: string|null, to?: string|null, group?: string|null, project?: string|null, force?: bool} $filters
     * @return array<string, int>
     */
    public function plan(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $force = (bool) ($filters['force'] ?? false);
        $summary = [
            'dates' => 0,
            'groups' => 0,
            'created' => 0,
            'updated' => 0,
            'existing_skipped' => 0,
            'completed_skipped' => 0,
            'empty_skipped' => 0,
        ];
        $groups = $this->groups($filters);
        $summary['groups'] = $groups->count();

        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString()) as $date) {
            $summary['dates']++;

            foreach ($groups as $group) {
                $equipmentCount = $this->sync->equipmentForGroup($group)->count();
                $status = $equipmentCount === 0
                    ? WialonReportSyncItem::STATUS_SKIPPED
                    : WialonReportSyncItem::STATUS_PENDING;

                $item = WialonReportSyncItem::query()
                    ->where('sync_type', WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY)
                    ->whereDate('report_date', $date->toDateString())
                    ->where('wialon_group_id', $group->wialon_group_id)
                    ->first();

                if ($item && $item->status === WialonReportSyncItem::STATUS_COMPLETED && ! $force) {
                    $summary['completed_skipped']++;
                    continue;
                }

                if ($item && ! $force) {
                    if ($item->wialon_group_name !== $group->name) {
                        $item->forceFill(['wialon_group_name' => $group->name])->save();
                    }

                    $summary['existing_skipped']++;
                    continue;
                }

                $values = [
                    'wialon_group_name' => $group->name,
                    'status' => $status,
                    'attempts' => $force ? 0 : (int) ($item?->attempts ?? 0),
                    'rows_received' => $force ? 0 : (int) ($item?->rows_received ?? 0),
                    'rows_saved' => $force ? 0 : (int) ($item?->rows_saved ?? 0),
                    'started_at' => null,
                    'finished_at' => $status === WialonReportSyncItem::STATUS_SKIPPED ? now($this->timezone()) : null,
                    'next_retry_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => $status === WialonReportSyncItem::STATUS_SKIPPED ? 'No eligible equipment for this group.' : null,
                    'run_id' => null,
                ];

                WialonReportSyncItem::query()->updateOrCreate(
                    [
                        'sync_type' => WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY,
                        'report_date' => $date->toDateString(),
                        'wialon_group_id' => (string) $group->wialon_group_id,
                    ],
                    $values
                );

                if ($item) {
                    $summary['updated']++;
                } else {
                    $summary['created']++;
                }

                if ($status === WialonReportSyncItem::STATUS_SKIPPED) {
                    $summary['empty_skipped']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param array{limit?: int, date?: string|null, group?: string|null, retry_failed?: bool, details?: bool} $filters
     * @return array<string, mixed>
     */
    public function run(array $filters): array
    {
        $lock = Cache::lock(self::LOCK_KEY, (int) config('fleet.wialon.shift_sync_lock_seconds', 900));

        if (! $lock->get()) {
            return ['locked' => true, 'processed' => 0, 'completed' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
        }

        $summary = ['locked' => false, 'processed' => 0, 'completed' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
        $runId = (string) Str::uuid();

        try {
            $this->recoverStaleRunning();
            $items = $this->dueItems($filters);

            if ($items->isEmpty()) {
                return $summary;
            }

            $sid = null;

            try {
                $sid = $this->sessions->sid();
            } catch (Throwable $exception) {
                $this->deferBatchForAuthFailure($items, $exception, $runId);

                return array_merge($summary, ['retry' => $items->count(), 'processed' => $items->count()]);
            }

            foreach ($items as $index => $item) {
                $result = $this->processItem($item, $sid, $runId, (bool) ($filters['details'] ?? false));
                $summary['processed']++;
                $summary[$result['status']]++;

                if (($filters['details'] ?? false) && isset($result['detail'])) {
                    $summary['details'][] = $result['detail'];
                }

                if ($result['auth_failed'] ?? false) {
                    $remaining = $items->slice($index + 1)->values();

                    if ($remaining->isNotEmpty()) {
                        $deferred = $this->deferRemainingAfterAuthFailure($remaining, $result['exception'] ?? null, $runId);
                        $summary['processed'] += $deferred['processed'];
                        $summary['retry'] += $deferred['retry'];
                        $summary['failed'] += $deferred['failed'];
                    }

                    break;
                }
            }
        } finally {
            $this->sessions->close();
            optional($lock)->release();
        }

        return $summary;
    }

    /**
     * @param array{from?: string|null, to?: string|null} $filters
     * @return array<string, mixed>
     */
    public function status(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $rows = WialonReportSyncItem::query()
            ->where('sync_type', WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->get();

        return [
            'planned' => $rows->count(),
            'counts' => $rows->groupBy('status')->map->count()->all(),
            'groups_total' => $rows->pluck('wialon_group_id')->unique()->count(),
            'dates_total' => $rows->pluck(fn (WialonReportSyncItem $item): string => $item->report_date?->toDateString() ?? '')->filter()->unique()->count(),
            'rows_received' => $rows->sum('rows_received'),
            'rows_saved' => $rows->sum('rows_saved'),
            'last_completed' => $rows->where('status', WialonReportSyncItem::STATUS_COMPLETED)->sortByDesc('finished_at')->first(),
            'next_retry' => $rows->where('status', WialonReportSyncItem::STATUS_RETRY)->filter(fn (WialonReportSyncItem $item): bool => $item->next_retry_at !== null)->sortBy('next_retry_at')->first(),
            'errors' => $rows->whereIn('status', [WialonReportSyncItem::STATUS_RETRY, WialonReportSyncItem::STATUS_FAILED])->groupBy(fn (WialonReportSyncItem $item): string => (string) ($item->last_error_code ?: 'unknown'))->map->count()->all(),
            'by_date' => $rows->groupBy(fn (WialonReportSyncItem $item): string => $item->report_date?->toDateString() ?? 'unknown')
                ->map(fn (Collection $items): array => $items->groupBy('status')->map->count()->all())
                ->all(),
            'problem_items' => $rows->whereIn('status', [WialonReportSyncItem::STATUS_RETRY, WialonReportSyncItem::STATUS_FAILED])->values(),
        ];
    }

    /**
     * @param array{date?: string|null, group?: string|null, all_failed?: bool} $filters
     * @return int
     */
    public function retryFailed(array $filters): int
    {
        $query = WialonReportSyncItem::query()
            ->where('sync_type', WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY)
            ->where('status', WialonReportSyncItem::STATUS_FAILED)
            ->when(! ($filters['all_failed'] ?? false), function (Builder $query) use ($filters): void {
                if (! empty($filters['date'])) {
                    $query->whereDate('report_date', $filters['date']);
                }

                if (! empty($filters['group'])) {
                    $query->where('wialon_group_id', trim((string) $filters['group']));
                }
            });

        return $query->update([
            'status' => WialonReportSyncItem::STATUS_RETRY,
            'next_retry_at' => now($this->timezone()),
            'finished_at' => null,
            'run_id' => null,
            'updated_at' => now($this->timezone()),
        ]);
    }

    /**
     * @return Collection<int, ProjectWialonGroup>
     */
    private function groups(array $filters): Collection
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when($this->hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when(! empty($filters['group']), fn (Builder $query) => $query->where('wialon_group_id', trim((string) $filters['group'])))
            ->when(! empty($filters['project']), function (Builder $query) use ($filters): void {
                $project = trim((string) $filters['project']);
                $query->whereHas('project', function (Builder $query) use ($project): void {
                    ctype_digit($project)
                        ? $query->whereKey((int) $project)
                        : $query->where('name', $project);
                });
            })
            ->orderBy('wialon_group_id')
            ->get();
    }

    private function processItem(WialonReportSyncItem $item, string &$sid, string $runId, bool $details): array
    {
        $this->markRunning($item, $runId);

        try {
            return $this->executeItem($item, $sid, $runId, $details);
        } catch (Throwable $exception) {
            if ($this->isAuthError($exception)) {
                try {
                    $newSid = $this->sessions->reauthorizeOnce();

                    if ($newSid !== null) {
                        $sid = $newSid;

                        try {
                            return $this->executeItem($item->refresh(), $sid, $runId, $details);
                        } catch (Throwable $afterReauthException) {
                            $status = $this->markRetryOrFailed($item->refresh(), $afterReauthException, $runId);

                            return [
                                'status' => $status,
                                'auth_failed' => $this->isAuthError($afterReauthException),
                                'exception' => $afterReauthException,
                            ];
                        }
                    }
                } catch (Throwable $authException) {
                    $status = $this->markRetryOrFailed($item->refresh(), $authException, $runId);

                    return ['status' => $status, 'auth_failed' => true, 'exception' => $authException];
                }
            }

            $status = $this->markRetryOrFailed($item->refresh(), $exception, $runId);

            return ['status' => $status, 'auth_failed' => false, 'exception' => $exception];
        }
    }

    private function executeItem(WialonReportSyncItem $item, string $sid, string $runId, bool $details): array
    {
        $group = ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->where('wialon_group_id', $item->wialon_group_id)
            ->first();

        if (! $group) {
            throw new \RuntimeException('Wialon group is not mapped in project_wialon_groups.');
        }

        if ($this->sync->equipmentForGroup($group)->isEmpty()) {
            $item->forceFill([
                'status' => WialonReportSyncItem::STATUS_SKIPPED,
                'rows_received' => 0,
                'rows_saved' => 0,
                'finished_at' => now($this->timezone()),
                'last_error_code' => null,
                'last_error_message' => 'No eligible equipment for this group.',
                'run_id' => $runId,
            ])->save();

            return ['status' => 'skipped', 'auth_failed' => false];
        }

        $date = CarbonImmutable::parse($item->report_date, $this->timezone());
        $report = $this->reports->executeForGroupWithSession($group, $date->startOfDay(), $date->endOfDay(), $sid);
        $parsed = $this->parser->parse($report);
        $result = $this->sync->syncGroup(
            $group,
            $date->startOfDay(),
            $date->endOfDay(),
            $parsed['records'],
            [
                'resource_id' => $report['resource_id'],
                'template_id' => $report['template_id'],
                'template_name' => $report['template_name'],
            ],
            null,
            $details
        );
        $rowsSaved = (int) ($result['unit_days'] ?? 0);

        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_COMPLETED,
            'rows_received' => count($parsed['records']),
            'rows_saved' => $rowsSaved,
            'finished_at' => now($this->timezone()),
            'next_retry_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'run_id' => $runId,
        ])->save();

        return [
            'status' => 'completed',
            'auth_failed' => false,
            'detail' => [
                'date' => $item->report_date?->toDateString(),
                'group' => $item->wialon_group_id,
                'name' => $item->wialon_group_name,
                'rows_received' => count($parsed['records']),
                'rows_saved' => $rowsSaved,
            ],
        ];
    }

    private function markRunning(WialonReportSyncItem $item, string $runId): void
    {
        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_RUNNING,
            'attempts' => (int) $item->attempts + 1,
            'started_at' => now($this->timezone()),
            'finished_at' => null,
            'next_retry_at' => null,
            'run_id' => $runId,
        ])->save();
    }

    private function markRetryOrFailed(WialonReportSyncItem $item, Throwable $exception, string $runId): string
    {
        $code = $this->errorCode($exception);
        $temporary = $this->isTemporaryError($exception);
        $failed = ! $temporary || (int) $item->attempts >= 3;
        $status = $failed ? WialonReportSyncItem::STATUS_FAILED : WialonReportSyncItem::STATUS_RETRY;

        $item->forceFill([
            'status' => $status,
            'finished_at' => now($this->timezone()),
            'next_retry_at' => $failed ? null : $this->nextRetryAt((int) $item->attempts),
            'last_error_code' => $code,
            'last_error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'run_id' => $runId,
        ])->save();

        return $failed ? 'failed' : 'retry';
    }

    private function deferBatchForAuthFailure(Collection $items, Throwable $exception, string $runId): void
    {
        foreach ($items as $item) {
            $this->markRunning($item, $runId);
            $this->markRetryOrFailed($item->refresh(), $exception, $runId);
        }
    }

    /**
     * @return array{processed: int, retry: int, failed: int}
     */
    private function deferRemainingAfterAuthFailure(Collection $items, ?Throwable $exception, string $runId): array
    {
        $summary = ['processed' => 0, 'retry' => 0, 'failed' => 0];
        $exception ??= new \RuntimeException('Wialon authentication failed for this package.');

        foreach ($items as $item) {
            $this->markRunning($item, $runId);
            $status = $this->markRetryOrFailed($item->refresh(), $exception, $runId);
            $summary['processed']++;
            $summary[$status]++;
        }

        return $summary;
    }

    private function dueItems(array $filters): Collection
    {
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));
        $statuses = [WialonReportSyncItem::STATUS_PENDING, WialonReportSyncItem::STATUS_RETRY];

        if ($filters['retry_failed'] ?? false) {
            $statuses[] = WialonReportSyncItem::STATUS_FAILED;
        }

        return WialonReportSyncItem::query()
            ->where('sync_type', WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY)
            ->whereIn('status', $statuses)
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now($this->timezone()));
            })
            ->when(! empty($filters['date']), fn (Builder $query) => $query->whereDate('report_date', $filters['date']))
            ->when(! empty($filters['group']), fn (Builder $query) => $query->where('wialon_group_id', trim((string) $filters['group'])))
            ->orderBy('report_date')
            ->orderBy('wialon_group_id')
            ->limit($limit)
            ->get();
    }

    private function recoverStaleRunning(): int
    {
        $threshold = now($this->timezone())->subSeconds((int) config('fleet.wialon.shift_sync_running_timeout_seconds', 1800));

        return WialonReportSyncItem::query()
            ->where('sync_type', WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY)
            ->where('status', WialonReportSyncItem::STATUS_RUNNING)
            ->where('started_at', '<', $threshold)
            ->update([
                'status' => WialonReportSyncItem::STATUS_RETRY,
                'next_retry_at' => now($this->timezone()),
                'finished_at' => now($this->timezone()),
                'last_error_code' => 'stale_running',
                'last_error_message' => 'Recovered stale running shift sync item.',
                'updated_at' => now($this->timezone()),
            ]);
    }

    private function nextRetryAt(int $attempts): CarbonInterface
    {
        $minutes = match ($attempts) {
            1 => 5,
            2 => 15,
            default => 60,
        };

        return now($this->timezone())->addMinutes($minutes);
    }

    private function isTemporaryError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());
        $code = $this->errorCode($exception);

        return in_array($code, ['1', '2', '4', '5', '8', '1003', '1004'], true)
            || str_contains($message, 'timeout')
            || str_contains($message, 'temporar')
            || str_contains($message, 'busy')
            || str_contains($message, 'transport')
            || str_contains($message, 'http error');
    }

    private function isAuthError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());
        $code = $this->errorCode($exception);

        return in_array($code, ['8', '1003'], true) || str_contains($message, 'token/login') || str_contains($message, 'auth');
    }

    private function errorCode(Throwable $exception): string
    {
        if (preg_match('/Wialon API error\s+([0-9]+)/i', $exception->getMessage(), $matches)) {
            return $matches[1];
        }

        if (str_contains(mb_strtolower($exception->getMessage()), 'not configured')) {
            return 'configuration';
        }

        if (str_contains(mb_strtolower($exception->getMessage()), 'template')) {
            return 'template';
        }

        if (str_contains(mb_strtolower($exception->getMessage()), 'group')) {
            return 'group';
        }

        return 'exception';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $filters): array
    {
        $timezone = $this->timezone();
        $from = CarbonImmutable::parse((string) ($filters['from'] ?? now($timezone)->subDay()->toDateString()), $timezone)->startOfDay();
        $to = CarbonImmutable::parse((string) ($filters['to'] ?? now($timezone)->toDateString()), $timezone)->endOfDay();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    private function timezone(): string
    {
        return (string) config('fleet_efficiency.timezone', config('app.timezone', 'Asia/Baku'));
    }
}
