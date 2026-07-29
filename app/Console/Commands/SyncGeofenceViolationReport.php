<?php

namespace App\Console\Commands;

use App\Models\GeofenceViolationReportRow;
use App\Models\GeofenceViolationSyncItem;
use App\Models\ProjectWialonGroup;
use App\Services\GeofenceViolationReportImporter;
use App\Services\GeofenceViolationReportParser;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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
        GeofenceViolationReportImporter $importer
    ): int {
        [$from, $to] = $this->period();

        if ($this->periodDays($from, $to) > max(1, (int) config('geofence_violations.max_report_period_days', 31))) {
            $this->error(sprintf(
                'The requested period exceeds the %d day limit for this Wialon report.',
                (int) config('geofence_violations.max_report_period_days', 31)
            ));

            return self::INVALID;
        }

        $groups = $this->groups();

        if ($groups->isEmpty()) {
            $this->warn('No active project Wialon groups found.');

            return $this->option('group') || $this->option('project')
                ? self::FAILURE
                : self::SUCCESS;
        }

        $settings = $this->settings($wialon);
        $totals = ['source' => 0, 'imported' => 0, 'rejected' => 0, 'skipped' => 0, 'malformed' => 0];
        $failures = 0;
        $sessionId = $wialon->loginByToken(false);

        try {
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
                    $report = $reportLock->run(fn (): array => $this->fetchReport(
                        $wialon,
                        $sessionId,
                        $settings,
                        $group->wialon_group_id,
                        $from->timestamp,
                        $to->timestamp
                    ));
                    $parsed = $parser->parse($report, $group, $from, $to);
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
                            'NON_CONTINUOUS_INTERVALS: %d report rows do not describe one continuous period; the group snapshot was not changed.',
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
     * @param  array{resource_id: int, template_id: int, chunk_size: int, interval_flags: int, timeout: int}  $settings
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
        $result = $wialon->executeReport(
            $settings['resource_id'],
            $settings['template_id'],
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

    private function groups()
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query
                ->where('active', true)
                ->excludeFromOperationalDashboard())
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
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
        foreach (['REPORT_SCHEMA_MISMATCH', 'MALFORMED_REPORT_ROWS', 'NON_CONTINUOUS_INTERVALS'] as $code) {
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
    }
}
