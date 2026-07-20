<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use App\Services\GeofenceReportViolationCalculator;
use App\Services\WialonGeozonReportParser;
use App\Services\WialonGeozonReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SyncGeozonApi extends Command
{
    protected $signature = 'fleet:sync-geozon-api
        {--from= : Start datetime in Asia/Baku}
        {--to= : End datetime in Asia/Baku}
        {--group= : Wialon group ID}
        {--project= : Project ID or exact project name}
        {--unit= : Limit parsed rows to a Wialon unit ID or unit name}
        {--details : Show per-row decisions}
        {--force : Re-run and upsert records for the same period}';

    protected $description = 'Synchronize foreign geofence visits from the Wialon "geozon api" report.';

    public function handle(
        WialonGeozonReportService $reports,
        WialonGeozonReportParser $parser,
        GeofenceReportViolationCalculator $calculator
    ): int {
        [$from, $to] = $this->period();
        $groups = $this->groups();

        if ($groups->isEmpty()) {
            $this->warn('No active project Wialon groups found.');

            return self::SUCCESS;
        }

        $totals = $this->emptyTotals();
        $failed = 0;

        foreach ($groups as $group) {
            $result = null;

            try {
                $report = $this->executeWithRetry($reports, $group, $from, $to);
                $parsed = $parser->parse($report);
                $context = [
                    'resource_id' => $report['resource_id'],
                    'template_id' => $report['template_id'],
                    'table_name' => $report['table_name'],
                    'from' => $from,
                    'to' => $to,
                ];

                if ((bool) $this->option('force')) {
                    $this->deleteExistingGroupPeriod($group, $from, $to);
                }

                $result = $calculator->processGroupReport(
                    $group,
                    $parsed['records'],
                    $context,
                    $this->option('unit') ? trim((string) $this->option('unit')) : null,
                    true
                );

                $result['parent_rows'] = $parsed['parent_rows'];
                $result['nested_rows'] = $parsed['nested_rows'];
                $result['report_status'] = 'ok';
                $this->addTotals($totals, $result);

                $this->line(sprintf(
                    '%s | %s | %s | parents=%d nested=%d foreign=%d saved=%d updated=%d',
                    $group->wialon_group_id,
                    $group->name,
                    $group->project?->name ?? '-',
                    $parsed['parent_rows'],
                    $parsed['nested_rows'],
                    $result['foreign_visits'],
                    $result['saved_records'],
                    $result['updated_records']
                ));

                if ($this->option('details')) {
                    $this->printDetails($result['details'] ?? []);
                }
            } catch (Throwable $exception) {
                $failed++;
                $totals['api_errors']++;
                $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());
                Log::warning('Wialon geozon api synchronization failed', [
                    'group_id' => $group->wialon_group_id,
                    'group_name' => $group->name,
                    'from' => $from->toDateTimeString(),
                    'to' => $to->toDateTimeString(),
                    'message' => $exception->getMessage(),
                ]);
            }

            $sleepMs = (int) config('fleet.wialon.geozon_report_sleep_ms', 250);

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['groups processed', $groups->count() - $failed],
                ['groups failed', $failed],
                ['parent geofence rows', $totals['parent_rows']],
                ['nested unit rows', $totals['nested_rows']],
                ['home visits', $totals['home_visits']],
                ['foreign visits', $totals['foreign_visits']],
                ['violations under threshold', $totals['violations_under_threshold']],
                ['violations at least 3 hours', $totals['violations_at_least_threshold']],
                ['unresolved units', $totals['unresolved_units']],
                ['unresolved geofences', $totals['unresolved_geofences']],
                ['ambiguous geofences', $totals['ambiguous_geofences']],
                ['project mismatches', $totals['project_mismatches']],
                ['saved records', $totals['saved_records']],
                ['updated records', $totals['updated_records']],
                ['API errors', $totals['api_errors']],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function deleteExistingGroupPeriod(ProjectWialonGroup $group, CarbonImmutable $from, CarbonImmutable $to): void
    {
        UnitForeignGeofenceInterval::query()
            ->where('source', GeofenceReportViolationCalculator::SOURCE)
            ->where('source_group_id', (string) $group->wialon_group_id)
            ->where('report_from', $from)
            ->where('report_to', $to)
            ->delete();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('app.timezone');
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), $timezone)
            : CarbonImmutable::now($timezone);
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), $timezone)
            : $to->subHours(max(1, (int) config('fleet.foreign_geofence.geozon_api_sync_lookback_hours', 24)));

        if ($from->greaterThan($to)) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private function groups()
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when($this->option('group'), fn (Builder $query, string $groupId) => $query->where('wialon_group_id', trim($groupId)))
            ->when($this->option('project'), function (Builder $query, string $project): void {
                $project = trim($project);
                $query->whereHas('project', function (Builder $query) use ($project): void {
                    if (ctype_digit($project)) {
                        $query->whereKey((int) $project);
                    } else {
                        $query->where('name', $project);
                    }
                });
            })
            ->orderBy('wialon_group_id')
            ->get();
    }

    private function executeWithRetry(
        WialonGeozonReportService $reports,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): array {
        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $reports->executeForGroup($group->wialon_group_id, $from, $to);
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! $this->isTemporaryError($exception) || $attempt === 3) {
                    throw $exception;
                }

                usleep($attempt * 500000);
            }
        }

        throw $lastException ?? new RuntimeException('Wialon geozon api report failed without an exception.');
    }

    private function isTemporaryError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'http error')
            || str_contains($message, 'api error 1')
            || str_contains($message, 'api error 2')
            || str_contains($message, 'api error 4')
            || str_contains($message, 'api error 5');
    }

    private function printDetails(array $details): void
    {
        if ($details === []) {
            return;
        }

        $this->table(
            ['Group', 'Home project', 'Home geofences', 'Parent geofence', 'Unit', 'Wialon ID', 'Reported project', 'Entry', 'Exit', 'Duration', 'Home', 'Foreign project', 'Included', 'Reason', 'Match'],
            collect($details)->map(fn (array $detail): array => [
                $detail['group_id'] ?? '',
                $detail['expected_home_project'] ?? '',
                implode(', ', $detail['allowed_home_geofences'] ?? []),
                $detail['parent_geofence'] ?? '',
                $detail['unit_name'] ?? '',
                $detail['wialon_unit_id'] ?? '',
                $detail['reported_project'] ?? '',
                $this->formatDate($detail['entry_time'] ?? null),
                $this->formatDate($detail['exit_time'] ?? null),
                $detail['duration_seconds'] ?? '',
                ($detail['is_home_geofence'] ?? false) ? 'yes' : 'no',
                $detail['foreign_project'] ?? '',
                ($detail['included'] ?? false) ? 'yes' : 'no',
                $detail['reason'] ?? '',
                trim(($detail['match_status'] ?? '').' '.($detail['match_method'] ?? '')),
            ])->all()
        );
    }

    private function formatDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : '';
    }

    private function addTotals(array &$totals, array $result): void
    {
        foreach ($totals as $key => $value) {
            $totals[$key] += (int) ($result[$key] ?? 0);
        }
    }

    private function emptyTotals(): array
    {
        return [
            'parent_rows' => 0,
            'nested_rows' => 0,
            'home_visits' => 0,
            'foreign_visits' => 0,
            'violations_under_threshold' => 0,
            'violations_at_least_threshold' => 0,
            'unresolved_units' => 0,
            'unresolved_geofences' => 0,
            'ambiguous_geofences' => 0,
            'project_mismatches' => 0,
            'saved_records' => 0,
            'updated_records' => 0,
            'api_errors' => 0,
        ];
    }
}
