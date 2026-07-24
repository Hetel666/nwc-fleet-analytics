<?php

namespace App\Console\Commands;

use App\Models\ProjectWialonGroup;
use App\Services\FleetShiftDailyStatsSyncService;
use App\Services\WialonShiftReportParser;
use App\Services\WialonShiftReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SyncShiftDailyStats extends Command
{
    protected $signature = 'fleet:sync-shift-daily-stats
        {--date= : Single date in YYYY-MM-DD format}
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--group= : Wialon group ID}
        {--project= : Project ID or exact project name}
        {--unit= : Limit to Wialon/local ID or unit name}
        {--details : Show per-unit-day saved rows}
        {--force : Re-run and update existing unit-day rows}';

    protected $description = 'Synchronize daily daytime/overtime stats from the Wialon shift report.';

    public function handle(
        WialonShiftReportService $reports,
        WialonShiftReportParser $parser,
        FleetShiftDailyStatsSyncService $sync
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
            try {
                $unitFilter = $this->option('unit') ? trim((string) $this->option('unit')) : null;
                $equipmentCount = $sync->equipmentForGroup($group, $unitFilter)->count();

                if ($equipmentCount === 0) {
                    $this->line(sprintf(
                        '%s | %s | %s | equipment=0 unit-days=0 daytime=0 overtime=0 unknown=0 saved=0 updated=0',
                        $group->wialon_group_id,
                        $group->name,
                        $group->project?->name ?? '-'
                    ));

                    continue;
                }

                $report = $this->executeWithRetry($reports, $group, $from, $to);
                $parsed = $parser->parse($report);
                $context = [
                    'resource_id' => $report['resource_id'],
                    'template_id' => $report['template_id'],
                    'template_name' => $report['template_name'],
                ];
                $result = $sync->syncGroup(
                    $group,
                    $from,
                    $to,
                    $parsed['records'],
                    $context,
                    $unitFilter,
                    (bool) $this->option('details')
                );

                $this->addTotals($totals, $result);

                $this->line(sprintf(
                    '%s | %s | %s | equipment=%d unit-days=%d daytime=%d overtime=%d unknown=%d saved=%d updated=%d',
                    $group->wialon_group_id,
                    $group->name,
                    $group->project?->name ?? '-',
                    $result['equipment_count'],
                    $result['unit_days'],
                    $result['daytime_rows'],
                    $result['overtime_rows'],
                    $result['unknown_rows'],
                    $result['saved_records'],
                    $result['updated_records']
                ));

                if ($this->option('details')) {
                    $this->printDetails($result['details'] ?? []);
                }
            } catch (Throwable $exception) {
                $failed++;
                $totals['report_errors']++;
                $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());
                Log::warning('Wialon shift daily stats synchronization failed', [
                    'group_id' => $group->wialon_group_id,
                    'group_name' => $group->name,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'message' => $exception->getMessage(),
                ]);
            }

            $sleepMs = (int) config('fleet.wialon.shift_report_sleep_ms', 250);

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
                ['equipment count', $totals['equipment_count']],
                ['unit-day rows', $totals['unit_days']],
                ['daytime rows', $totals['daytime_rows']],
                ['overtime rows', $totals['overtime_rows']],
                ['unknown rows', $totals['unknown_rows']],
                ['saved records', $totals['saved_records']],
                ['updated records', $totals['updated_records']],
                ['report errors', $totals['report_errors']],
            ]
        );

        $this->table(
            ['Status', 'Count'],
            collect($totals['status_counts'])->map(fn (int $count, string $status): array => [$status, $count])->all()
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('fleet_efficiency.timezone', 'Asia/Baku');
        $date = $this->option('date');
        $from = $date
            ? CarbonImmutable::parse((string) $date, $timezone)->startOfDay()
            : CarbonImmutable::parse((string) ($this->option('from') ?: now($timezone)->subDay()->toDateString()), $timezone)->startOfDay();
        $to = $date
            ? CarbonImmutable::parse((string) $date, $timezone)->endOfDay()
            : CarbonImmutable::parse((string) ($this->option('to') ?: $from->toDateString()), $timezone)->endOfDay();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
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
        WialonShiftReportService $reports,
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): array {
        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $reports->executeForGroup($group, $from, $to);
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! $this->isTemporaryError($exception) || $attempt === 3) {
                    throw $exception;
                }

                usleep($attempt * 2000000);
            }
        }

        throw $lastException ?? new RuntimeException('Wialon shift report failed without an exception.');
    }

    private function isTemporaryError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'http error')
            || str_contains($message, 'api error 1')
            || str_contains($message, 'api error 2')
            || str_contains($message, 'api error 4')
            || str_contains($message, 'api error 5')
            || str_contains($message, 'api error 8')
            || str_contains($message, 'api error 1003')
            || str_contains($message, 'api error 1004');
    }

    /**
     * @param array<int, array<string, mixed>> $details
     */
    private function printDetails(array $details): void
    {
        if ($details === []) {
            return;
        }

        $this->table(
            ['Date', 'Unit', 'Wialon ID', 'Type', 'Ownership', 'Project', 'Daytime', 'Overtime', 'Total', 'Day status', 'Has overtime', 'Source', 'Reason'],
            collect($details)->map(fn (array $row): array => [
                $row['date'] ?? '',
                $row['unit'] ?? '',
                $row['wialon_id'] ?? '',
                $row['type'] ?? '',
                $row['ownership'] ?? '',
                $row['project'] ?? '',
                $row['daytime_hours'] ?? 'NULL',
                $row['overtime_hours'] ?? 'NULL',
                $row['total_hours'] ?? 'NULL',
                $row['day_status'] ?? '',
                $row['has_overtime'] === null ? 'unknown' : (($row['has_overtime'] ?? false) ? 'yes' : 'no'),
                $row['source'] ?? '',
                $row['reason'] ?? '',
            ])->all()
        );
    }

    private function addTotals(array &$totals, array $result): void
    {
        foreach (['equipment_count', 'unit_days', 'daytime_rows', 'overtime_rows', 'unknown_rows', 'saved_records', 'updated_records'] as $key) {
            $totals[$key] += (int) ($result[$key] ?? 0);
        }

        foreach (($result['status_counts'] ?? []) as $status => $count) {
            $totals['status_counts'][$status] = ($totals['status_counts'][$status] ?? 0) + (int) $count;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTotals(): array
    {
        return [
            'equipment_count' => 0,
            'unit_days' => 0,
            'daytime_rows' => 0,
            'overtime_rows' => 0,
            'unknown_rows' => 0,
            'saved_records' => 0,
            'updated_records' => 0,
            'report_errors' => 0,
            'status_counts' => [],
        ];
    }
}
