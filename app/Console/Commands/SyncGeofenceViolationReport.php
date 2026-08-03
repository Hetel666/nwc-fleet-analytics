<?php

namespace App\Console\Commands;

use App\Models\GeofenceViolationReportRow;
use App\Models\GeofenceViolationSyncItem;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\GeofenceViolationReportImporter;
use App\Services\GeofenceViolationReportParser;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use App\Support\GeofenceExcludedGroups;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SyncGeofenceViolationReport extends Command
{
    protected $signature = 'fleet:sync-geofence-violations-report
        {--from= : Start datetime in Asia/Baku}
        {--to= : End datetime in Asia/Baku}
        {--group= : Wialon group ID}
        {--project= : Project ID or exact project name}
        {--force : Replace stored rows covered by a successful report result}';

    protected $description = 'Fetch and import the Wialon "Geofence Pozuntuları api" report.';

    public function handle(
        WialonService $wialon,
        WialonReportSessionLock $reportLock,
        GeofenceViolationReportParser $parser,
        GeofenceViolationReportImporter $importer,
        GeofenceExcludedGroups $excludedGroups
    ): int {
        [$from, $to] = $this->period();

        if ($this->periodDays($from, $to) > max(1, (int) config('geofence_violations.max_report_period_days', 31))) {
            $this->error(sprintf(
                'The requested period exceeds the %d day limit for this Wialon report.',
                (int) config('geofence_violations.max_report_period_days', 31)
            ));

            return self::INVALID;
        }

        $groups = $this->groups($excludedGroups);

        if ($groups->isEmpty()) {
            if ($this->option('group') || $this->option('project')) {
                $this->error(GeofenceExcludedGroups::MESSAGE);

                return self::INVALID;
            }

            $this->warn('No active project Wialon groups found.');

            return self::SUCCESS;
        }

        $settings = $this->settings($wialon);
        $totals = ['source' => 0, 'imported' => 0, 'rejected' => 0, 'skipped' => 0, 'malformed' => 0];
        $failures = 0;
        $sessionId = $wialon->loginByToken(false);

        try {
            $settings['report_template'] = $this->fullDetailReportTemplate(
                $wialon->getReportTemplateData(
                    $settings['resource_id'],
                    $settings['template_id'],
                    $sessionId
                )
            );

            foreach ($groups as $group) {
                $checkpoint = $this->checkpoint($group, $from, $to);
                $parsed = null;
                $result = null;

                if ($checkpoint->status === GeofenceViolationSyncItem::STATUS_COMPLETED
                    && ! (bool) $this->option('force')) {
                    $totals['source'] += $checkpoint->source_rows;
                    $totals['imported'] += $checkpoint->imported_rows;
                    $totals['skipped'] += $checkpoint->skipped_rows;
                    $this->line($group->wialon_group_id.' | '.$group->name.' | checkpoint=completed, skipped');

                    continue;
                }

                $checkpoint->forceFill([
                    'status' => GeofenceViolationSyncItem::STATUS_RUNNING,
                    'attempts' => $checkpoint->attempts + 1,
                    'source_rows' => 0,
                    'imported_rows' => 0,
                    'rejected_rows' => 0,
                    'skipped_rows' => 0,
                    'malformed_rows' => 0,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'started_at' => now(config('app.timezone')),
                    'completed_at' => null,
                ])->save();

                try {
                    $parsed = $this->fetchParsedReport(
                        $wialon,
                        $reportLock,
                        $sessionId,
                        $settings,
                        $group,
                        $from,
                        $to,
                        $parser
                    );
                    $totals['source'] += $parsed['source_rows'];
                    $totals['skipped'] += $parsed['skipped_types'];
                    $totals['malformed'] += $parsed['malformed_rows'];

                    if ($parsed['table_count'] > 0 && $parsed['matched_tables'] === 0) {
                        throw new RuntimeException(
                            'REPORT_SCHEMA_MISMATCH: The expected unit_group_zones_visit report table is missing.'
                        );
                    }

                    if ($parsed['malformed_rows'] > 0) {
                        throw new RuntimeException(sprintf(
                            'MALFORMED_REPORT_ROWS: Report contains %d malformed rows; the group snapshot was not changed.',
                            $parsed['malformed_rows']
                        ));
                    }

                    $result = $importer->replaceGroupSnapshot(
                        $group,
                        $from,
                        $to,
                        $parsed['records'],
                        now(config('app.timezone')),
                        (bool) $this->option('force')
                    );
                    $totals['imported'] += $result['imported'];
                    $totals['rejected'] += $result['rejected'];

                    if ($result['rejected'] > 0) {
                        throw new RuntimeException(sprintf(
                            'INVALID_REPORT_ROWS: %d report rows failed validation; the group snapshot was not changed.',
                            $result['rejected']
                        ));
                    }

                    $checkpoint->forceFill([
                        'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
                        'source_rows' => $parsed['source_rows'],
                        'imported_rows' => $result['imported'],
                        'rejected_rows' => 0,
                        'skipped_rows' => $parsed['skipped_types'],
                        'malformed_rows' => 0,
                        'completed_at' => now(config('app.timezone')),
                    ])->save();

                    $this->line(sprintf(
                        '%s | %s | source=%d imported=%d skipped_types=%d',
                        $group->wialon_group_id,
                        $group->name,
                        $parsed['source_rows'],
                        $result['imported'],
                        $parsed['skipped_types']
                    ));
                } catch (Throwable $exception) {
                    $failures++;
                    $checkpoint->forceFill([
                        'status' => GeofenceViolationSyncItem::STATUS_FAILED,
                        'source_rows' => (int) ($parsed['source_rows'] ?? 0),
                        'imported_rows' => 0,
                        'rejected_rows' => (int) ($result['rejected'] ?? 0),
                        'skipped_rows' => (int) ($parsed['skipped_types'] ?? 0),
                        'malformed_rows' => (int) ($parsed['malformed_rows'] ?? 0),
                        'last_error_code' => $this->errorCode($exception),
                        'last_error_message' => mb_substr($exception->getMessage(), 0, 4000),
                        'completed_at' => now(config('app.timezone')),
                    ])->save();
                    $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());
                    Log::warning('Geofence violations report synchronization failed', [
                        'group_id' => $group->wialon_group_id,
                        'project_id' => $group->project_id,
                        'from' => $from->toDateTimeString(),
                        'to' => $to->toDateTimeString(),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            try {
                $wialon->cleanupReportResult($sessionId);
                $wialon->logoutSession($sessionId);
            } catch (Throwable) {
                // The report result is already persisted locally; session cleanup is best-effort.
            }
        }

        $this->pruneOperationalData();

        $this->table(['Metric', 'Value'], [
            ['groups processed', $groups->count() - $failures],
            ['groups failed', $failures],
            ['source rows', $totals['source']],
            ['imported periods', $totals['imported']],
            ['rejected periods', $totals['rejected']],
            ['skipped equipment types', $totals['skipped']],
            ['malformed rows', $totals['malformed']],
        ]);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fetch this module's report through its own Wialon session.
     *
     * @param  array{resource_id: int, template_id: int, report_template: array<string, mixed>, chunk_size: int, interval_flags: int, timeout: int}  $settings
     * @return array{result: array<string, mixed>, tables: array<int, array<string, mixed>>}
     */
    private function fetchReport(
        WialonService $wialon,
        string $sessionId,
        array $settings,
        int|string $objectId,
        int $from,
        int $to
    ): array {
        $wialon->cleanupReportResult($sessionId);
        $result = $wialon->executeReportTemplate(
            $settings['resource_id'],
            $settings['report_template'],
            $objectId,
            $from,
            $to,
            $settings['interval_flags'],
            $sessionId,
            false,
            $settings['timeout']
        );
        $tables = [];

        foreach (($result['reportResult']['tables'] ?? []) as $tableIndex => $table) {
            $rowCount = (int) ($table['rows'] ?? 0);
            $rows = [];

            if ($rowCount > 0) {
                $levels = array_values(array_unique([
                    max(1, (int) ($table['level'] ?? 1) - 1),
                    max(0, (int) ($table['level'] ?? 1)),
                    0,
                ]));

                foreach ($levels as $level) {
                    try {
                        $rows = $this->selectRows(
                            $wialon,
                            $sessionId,
                            (int) $tableIndex,
                            $rowCount,
                            $settings['chunk_size'],
                            $level
                        );
                    } catch (RuntimeException) {
                        $rows = [];
                    }

                    if ($rows !== []) {
                        break;
                    }
                }

                if ($rows === []) {
                    for ($indexFrom = 0; $indexFrom < $rowCount; $indexFrom += $settings['chunk_size']) {
                        $rows = array_merge($rows, $wialon->getReportResultRows(
                            (int) $tableIndex,
                            $indexFrom,
                            min($rowCount - 1, $indexFrom + $settings['chunk_size'] - 1),
                            $sessionId
                        ));
                    }
                }
            }

            $tables[] = [
                'index' => (int) $tableIndex,
                'table' => $table,
                'rows' => $rows,
            ];
        }

        return ['result' => $result, 'tables' => $tables];
    }

    /**
     * Execute a report with a dedicated session and renew that session when
     * Wialon reports a temporary execution limit or an expired report context.
     *
     * @param  array{resource_id: int, template_id: int, report_template: array<string, mixed>, chunk_size: int, interval_flags: int, timeout: int}  $settings
     * @return array{result: array<string, mixed>, tables: array<int, array<string, mixed>>}
     */
    private function fetchReportWithRetry(
        WialonService $wialon,
        WialonReportSessionLock $reportLock,
        string &$sessionId,
        array $settings,
        int|string $objectId,
        int $from,
        int $to
    ): array {
        $attempts = max(1, (int) config('geofence_violations.report_attempts', 3));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $reportLock->run(fn (): array => $this->fetchReport(
                    $wialon,
                    $sessionId,
                    $settings,
                    $objectId,
                    $from,
                    $to
                ));
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! $this->isTemporaryWialonError($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                try {
                    $wialon->cleanupReportResult($sessionId);
                    $wialon->logoutSession($sessionId);
                } catch (Throwable) {
                    // The session is already invalid or has no report result.
                }

                usleep($attempt * max(100, (int) config(
                    'geofence_violations.report_retry_delay_ms',
                    2_000
                )) * 1_000);

                $sessionId = $wialon->loginByToken(false);
            }
        }

        throw $lastException ?? new RuntimeException('Wialon geofence violations report failed without an exception.');
    }

    /**
     * Confirm an empty result before treating it as a valid snapshot. Wialon
     * can occasionally return an empty table while the same report succeeds
     * immediately afterwards.
     *
     * @param  array{resource_id: int, template_id: int, report_template: array<string, mixed>, chunk_size: int, interval_flags: int, timeout: int}  $settings
     * @return array{records: array<int, array<string, mixed>>, source_rows: int, skipped_types: int, malformed_rows: int, matched_tables: int, table_count: int}
     */
    private function fetchParsedReport(
        WialonService $wialon,
        WialonReportSessionLock $reportLock,
        string &$sessionId,
        array $settings,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        GeofenceViolationReportParser $parser
    ): array {
        try {
            return $this->fetchParsedSnapshot(
                $wialon,
                $reportLock,
                $sessionId,
                $settings,
                $group,
                $from,
                $to,
                $parser
            );
        } catch (Throwable $exception) {
            if (! $this->isTemporaryWialonError($exception)
                || $from->diffInSeconds($to) <= $this->fallbackChunkSeconds()) {
                throw $exception;
            }

            Log::notice('Retrying geofence violations report in overlapping chunks', [
                'group_id' => $group->wialon_group_id,
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'message' => $exception->getMessage(),
            ]);

            return $this->fetchParsedReportInChunks(
                $wialon,
                $reportLock,
                $sessionId,
                $settings,
                $group,
                $from,
                $to,
                $parser
            );
        }
    }

    /**
     * @param  array{resource_id: int, template_id: int, report_template: array<string, mixed>, chunk_size: int, interval_flags: int, timeout: int}  $settings
     * @return array{records: array<int, array<string, mixed>>, source_rows: int, skipped_types: int, malformed_rows: int, matched_tables: int, table_count: int}
     */
    private function fetchParsedSnapshot(
        WialonService $wialon,
        WialonReportSessionLock $reportLock,
        string &$sessionId,
        array $settings,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        GeofenceViolationReportParser $parser
    ): array {
        $attempts = max(1, (int) config('geofence_violations.empty_snapshot_attempts', 2));
        $parsed = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $report = $this->fetchReportWithRetry(
                $wialon,
                $reportLock,
                $sessionId,
                $settings,
                $group->wialon_group_id,
                $from->timestamp,
                $to->timestamp
            );
            $parsed = $parser->parse($report, $group, $from, $to);

            if ($parsed['source_rows'] > 0 || $attempt === $attempts) {
                return $parsed;
            }

            usleep(max(100, (int) config(
                'geofence_violations.empty_snapshot_retry_delay_ms',
                2_000
            )) * 1_000);
        }

        return $parsed ?? [
            'records' => [],
            'source_rows' => 0,
            'skipped_types' => 0,
            'malformed_rows' => 0,
            'matched_tables' => 0,
            'table_count' => 0,
        ];
    }

    /**
     * Use overlapping report windows so a violation that crosses a chunk
     * boundary is still returned by Wialon's strict duration filter.
     *
     * @param  array{resource_id: int, template_id: int, report_template: array<string, mixed>, chunk_size: int, interval_flags: int, timeout: int}  $settings
     * @return array{records: array<int, array<string, mixed>>, source_rows: int, skipped_types: int, malformed_rows: int, matched_tables: int, table_count: int}
     */
    private function fetchParsedReportInChunks(
        WialonService $wialon,
        WialonReportSessionLock $reportLock,
        string &$sessionId,
        array $settings,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        GeofenceViolationReportParser $parser
    ): array {
        $chunkSeconds = $this->fallbackChunkSeconds();
        $overlapSeconds = max(
            (int) config('geofence_violations.minimum_duration_seconds', 10_800) + 1,
            (int) config('geofence_violations.fallback_overlap_seconds', 10_801)
        );
        $combined = [
            'records' => [],
            'source_rows' => 0,
            'skipped_types' => 0,
            'malformed_rows' => 0,
            'matched_tables' => 0,
            'table_count' => 0,
        ];

        for ($coreFrom = $from; $coreFrom->lte($to); $coreFrom = $coreFrom->addSeconds($chunkSeconds)) {
            $coreTo = $coreFrom->addSeconds($chunkSeconds - 1)->min($to);
            $chunkFrom = $coreFrom->subSeconds($overlapSeconds)->max($from);
            $chunkTo = $coreTo->addSeconds($overlapSeconds)->min($to);
            $parsed = $this->fetchParsedSnapshot(
                $wialon,
                $reportLock,
                $sessionId,
                $settings,
                $group,
                $chunkFrom,
                $chunkTo,
                $parser
            );

            foreach (['source_rows', 'skipped_types', 'malformed_rows', 'matched_tables', 'table_count'] as $metric) {
                $combined[$metric] += $parsed[$metric];
            }

            array_push($combined['records'], ...$parsed['records']);
        }

        $combined['records'] = $this->mergeChunkedRecords(
            $combined['records'],
            $group,
            $from,
            $to
        );

        return $combined;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function mergeChunkedRecords(
        array $records,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): array {
        $grouped = collect($records)
            ->groupBy(fn (array $record): string => filled($record['wialon_unit_id'] ?? null)
                ? 'id:'.$record['wialon_unit_id']
                : 'name:'.mb_strtolower(trim((string) ($record['equipment_name'] ?? ''))));
        $merged = [];
        $activeTolerance = max(1, (int) config('geofence_violations.active_end_tolerance_seconds', 300));

        foreach ($grouped as $unitRecords) {
            $sorted = $unitRecords->sortBy('exited_at')->values();
            $current = null;

            foreach ($sorted as $record) {
                $recordStart = CarbonImmutable::parse($record['exited_at'], config('app.timezone'));
                $recordEnd = CarbonImmutable::parse($record['last_confirmed_at'], config('app.timezone'));

                if ($current === null) {
                    $current = $record;
                    continue;
                }

                $currentEnd = CarbonImmutable::parse($current['last_confirmed_at'], config('app.timezone'));

                if ($recordStart->timestamp > $currentEnd->timestamp + 1) {
                    $merged[] = $this->finalizeChunkedRecord($current, $group, $from, $to, $activeTolerance);
                    $current = $record;
                    continue;
                }

                if ($recordEnd->gt($currentEnd)) {
                    $current['last_confirmed_at'] = $recordEnd->toDateTimeString();
                    $current['last_location'] = $record['last_location'] ?? $current['last_location'] ?? null;
                }

                $currentStart = CarbonImmutable::parse($current['exited_at'], config('app.timezone'));
                $current['outside_duration_seconds'] = max(
                    (int) ($current['outside_duration_seconds'] ?? 0),
                    (int) ($record['outside_duration_seconds'] ?? 0),
                    $currentStart->diffInSeconds(
                        CarbonImmutable::parse($current['last_confirmed_at'], config('app.timezone'))
                    )
                );
            }

            if ($current !== null) {
                $merged[] = $this->finalizeChunkedRecord($current, $group, $from, $to, $activeTolerance);
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function finalizeChunkedRecord(
        array $record,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $activeTolerance
    ): array {
        $exitedAt = CarbonImmutable::parse($record['exited_at'], config('app.timezone'));
        $lastConfirmedAt = CarbonImmutable::parse($record['last_confirmed_at'], config('app.timezone'));
        $isActive = abs($to->timestamp - $lastConfirmedAt->timestamp) <= $activeTolerance;
        $unitKey = filled($record['wialon_unit_id'] ?? null)
            ? (string) $record['wialon_unit_id']
            : mb_strtolower(trim((string) ($record['equipment_name'] ?? '')));

        $record['period_key'] = sha1(implode('|', [
            GeofenceViolationReportRow::REPORT_NAME,
            $group->wialon_group_id,
            $unitKey,
            $exitedAt->timestamp,
        ]));
        $record['ended_at'] = $isActive ? null : $lastConfirmedAt->toDateTimeString();
        $record['is_active'] = $isActive;
        $record['report_period_from'] = $from->toDateTimeString();
        $record['report_period_to'] = $to->toDateTimeString();
        $record['source_payload']['chunked_fetch'] = true;

        return $record;
    }

    private function fallbackChunkSeconds(): int
    {
        return max(1, (int) config('geofence_violations.fallback_chunk_hours', 24)) * 3_600;
    }

    private function isTemporaryWialonError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'http error')
            || str_contains($message, 'wialon api error 1 ')
            || str_contains($message, 'wialon api error 2 ')
            || str_contains($message, 'wialon api error 4 ')
            || str_contains($message, 'wialon api error 5 ')
            || str_contains($message, 'wialon api error 8 ')
            || str_contains($message, 'wialon api error 1003')
            || str_contains($message, 'wialon api error 1004');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectRows(
        WialonService $wialon,
        string $sessionId,
        int $tableIndex,
        int $rowCount,
        int $chunkSize,
        int $level
    ): array {
        $rows = [];

        for ($indexFrom = 0; $indexFrom < $rowCount; $indexFrom += $chunkSize) {
            $rows = array_merge($rows, $wialon->selectReportResultRows(
                $tableIndex,
                [
                    'type' => 'range',
                    'data' => [
                        'from' => $indexFrom,
                        'to' => min($rowCount - 1, $indexFrom + $chunkSize - 1),
                        'level' => $level,
                        'unitInfo' => 1,
                    ],
                ],
                $sessionId
            ));
        }

        return $rows;
    }

    /**
     * @return array{resource_id: int, template_id: int, chunk_size: int, interval_flags: int, timeout: int}
     */
    private function settings(WialonService $wialon): array
    {
        $resourceId = (int) config('geofence_violations.resource_id');
        $templateId = (int) config('geofence_violations.template_id');
        $templateName = (string) config('geofence_violations.report_name', GeofenceViolationReportRow::REPORT_NAME);

        if ($resourceId <= 0) {
            throw new RuntimeException('Geofence violations report resource id is not configured.');
        }

        if ($templateId <= 0) {
            $template = $wialon->findReportTemplateByName($resourceId, $templateName);

            if ($template === null) {
                throw new RuntimeException("Wialon report template '{$templateName}' was not found.");
            }

            $templateId = (int) $template['id'];
            $resourceId = (int) ($template['resource_id'] ?? $resourceId);
        }

        return [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'chunk_size' => max(1, (int) config('geofence_violations.chunk_size', 500)),
            'interval_flags' => (int) config('geofence_violations.interval_flags', 0),
            'timeout' => max(5, (int) config('geofence_violations.timeout', 60)),
        ];
    }

    /**
     * Execute a transient full-detail copy. The saved Wialon template and its
     * duration filter remain unchanged.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function fullDetailReportTemplate(array $template): array
    {
        $matched = false;

        if (! is_array($template['tbl'] ?? null)) {
            throw new RuntimeException(
                'REPORT_SCHEMA_MISMATCH: The report template does not contain tables.'
            );
        }

        foreach ($template['tbl'] as &$table) {
            if (($table['n'] ?? null) !== 'unit_group_zones_visit') {
                continue;
            }

            $table['f'] = (((int) ($table['f'] ?? 0)) & ~0x10) | 0x800;
            $matched = true;
        }
        unset($table);

        if (! $matched) {
            throw new RuntimeException(
                'REPORT_SCHEMA_MISMATCH: The expected unit_group_zones_visit report table is missing from the template.'
            );
        }

        return $template;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('app.timezone', 'Asia/Baku');
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), $timezone)
            : CarbonImmutable::now($timezone);
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), $timezone)
            : $to->startOfDay();

        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    private function groups(GeofenceExcludedGroups $excludedGroups)
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query
                ->where('active', true)
                ->excludeFromOperationalDashboard())
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->tap(fn (Builder $query) => $excludedGroups->applyAllowedProjectWialonGroups($query))
            ->when($this->option('group'), fn (Builder $query, string $groupId) => $query->where('wialon_group_id', trim($groupId)))
            ->when($this->option('project'), function (Builder $query, string $project): void {
                $project = trim($project);
                $query->whereHas('project', function (Builder $query) use ($project): void {
                    ctype_digit($project)
                        ? $query->whereKey((int) $project)
                        : $query->where('name', $project);
                });
            })
            ->orderBy('wialon_group_id')
            ->get();
    }

    private function periodDays(
        CarbonImmutable $from,
        CarbonImmutable $to
    ): int {
        return (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
    }

    private function checkpoint(
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): GeofenceViolationSyncItem {
        $checkpointKey = sha1(implode('|', [
            GeofenceViolationReportRow::REPORT_NAME,
            $group->id,
            $group->wialon_group_id,
            $from->timestamp,
            $to->timestamp,
        ]));

        return GeofenceViolationSyncItem::query()->firstOrCreate(
            ['checkpoint_key' => $checkpointKey],
            [
                'project_id' => $group->project_id,
                'project_wialon_group_id' => $group->id,
                'wialon_group_id' => (string) $group->wialon_group_id,
                'wialon_group_name' => $group->name,
                'ownership_type' => $group->ownership_type,
                'report_period_from' => $from,
                'report_period_to' => $to,
                'status' => GeofenceViolationSyncItem::STATUS_FAILED,
            ]
        );
    }

    private function errorCode(Throwable $exception): string
    {
        foreach (['REPORT_SCHEMA_MISMATCH', 'MALFORMED_REPORT_ROWS', 'INVALID_REPORT_ROWS'] as $code) {
            if (str_starts_with($exception->getMessage(), $code.':')) {
                return $code;
            }
        }

        return str_contains(mb_strtolower($exception->getMessage()), 'wialon')
            ? 'WIALON_REPORT_FAILED'
            : 'IMPORT_FAILED';
    }

    private function pruneOperationalData(): void
    {
        $checkpointCutoff = now(config('app.timezone'))->subDays(
            max(1, (int) config('geofence_violations.checkpoint_retention_days', 90))
        );
        $payloadCutoff = now(config('app.timezone'))->subDays(
            max(1, (int) config('geofence_violations.source_payload_retention_days', 90))
        );

        GeofenceViolationSyncItem::query()
            ->where('completed_at', '<', $checkpointCutoff)
            ->delete();

        GeofenceViolationReportRow::query()
            ->whereNotNull('source_payload')
            ->where('report_generated_at', '<', $payloadCutoff)
            ->update(['source_payload' => null]);

        $deletedRows = GeofenceViolationReportRow::query()
            ->where('report_name', GeofenceViolationReportRow::REPORT_NAME)
            ->where(function (Builder $query): void {
                $query->whereNull('report_period_from')
                    ->orWhereNull('report_period_to')
                    ->orWhereIn(
                        'project_id',
                        Project::query()
                            ->select('id')
                            ->whereIn('name', Project::dashboardOperationalExcludedNames())
                    );
            })
            ->delete();

        if ($deletedRows > 0) {
            Cache::forever('geofence_violations:data_version', sprintf('%.6F', microtime(true)));
        }

        GeofenceViolationSyncItem::query()
            ->whereIn(
                'project_id',
                Project::query()
                    ->select('id')
                    ->whereIn('name', Project::dashboardOperationalExcludedNames())
            )
            ->delete();
    }
}
