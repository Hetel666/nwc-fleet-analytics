<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\ProjectWialonGroup;
use App\Models\StatisticBackfillItem;
use App\Services\DashboardService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackfillFleetStatistics extends Command
{
    protected $signature = 'fleet:backfill-statistics
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--all-projects : Process every active project with Wialon groups}
        {--project= : Project database ID}
        {--chunk=daily : Processing chunk, currently only daily is supported}
        {--force : Rebuild already completed dates}
        {--dry-run : Show what would be processed without changing data}
        {--resume : Continue pending/failed items}
        {--retry=2 : Retry count for a failed day/project/ownership item}
        {--sleep-ms=250 : Pause between processed items to avoid Wialon throttling}';

    protected $description = 'Backfill daily Engine hours and Mileage statistics from Wialon reports.';

    public function handle(DashboardService $dashboard): int
    {
        $startedAt = microtime(true);
        $from = Carbon::parse($this->option('from') ?: '2026-01-01', config('app.timezone'))->toDateString();
        $to = Carbon::parse($this->option('to') ?: now(config('app.timezone'))->toDateString(), config('app.timezone'))->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($this->option('chunk') !== 'daily') {
            $this->error('Only --chunk=daily is supported.');

            return self::INVALID;
        }

        if (! $this->option('all-projects') && ! $this->option('project')) {
            $this->error('Use --all-projects or --project=<project_id>.');

            return self::INVALID;
        }

        $targets = $this->targets();

        if ($targets->isEmpty()) {
            $this->warn('No matching active project Wialon groups found.');

            return self::SUCCESS;
        }

        $dates = collect(CarbonPeriod::create($from, $to))
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->values();

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $maxAttempts = max(1, ((int) $this->option('retry')) + 1);
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $lockKey = 'fleet:backfill-statistics:'.sha1(json_encode([
            'from' => $from,
            'to' => $to,
            'project' => $this->option('project') ?: 'all',
        ]));
        $lock = Cache::lock($lockKey, 3600);

        if (! $dryRun && ! $lock->get()) {
            $this->error('The same backfill range is already running.');

            return self::FAILURE;
        }

        $summary = [
            'days' => $dates->count(),
            'tasks' => 0,
            'already_ready' => 0,
            'calculated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'processed_objects' => 0,
        ];

        try {
            foreach ($dates as $date) {
                foreach ($targets as $target) {
                    $summary['tasks']++;

                    if (! $force && $this->isReady($date, (int) $target->project_id, $target->ownership_type)) {
                        $summary['already_ready']++;

                        if (! $dryRun) {
                            $this->markCompleted($date, (int) $target->project_id, $target->ownership_type, $this->existingStatCount($date, (int) $target->project_id, $target->ownership_type));
                        }

                        $this->line("{$date} project {$target->project_id} {$target->ownership_type}: ready, skipped.");
                        continue;
                    }

                    if ($dryRun) {
                        $summary['skipped']++;
                        $this->line("{$date} project {$target->project_id} {$target->ownership_type}: would be processed.");
                        continue;
                    }

                    $item = StatisticBackfillItem::query()->updateOrCreate(
                        [
                            'stat_date' => $date,
                            'project_id' => (int) $target->project_id,
                            'ownership_type' => $target->ownership_type,
                        ],
                        [
                            'status' => StatisticBackfillItem::STATUS_PENDING,
                            'last_error' => null,
                        ]
                    );

                    if ($this->option('resume') && $item->status !== StatisticBackfillItem::STATUS_COMPLETED) {
                        $item->forceFill([
                            'status' => StatisticBackfillItem::STATUS_PENDING,
                            'attempts' => 0,
                            'last_error' => null,
                        ])->save();
                    }

                    $ok = false;
                    $lastError = null;

                    while (! $ok && $item->attempts < $maxAttempts) {
                        $item->forceFill([
                            'status' => StatisticBackfillItem::STATUS_PROCESSING,
                            'attempts' => $item->attempts + 1,
                            'started_at' => now(config('app.timezone')),
                            'last_error' => null,
                        ])->save();

                        try {
                            $result = $dashboard->syncDailyEngineHoursReport([
                                'date_from' => $date,
                                'date_to' => $date,
                                'project_id' => (int) $target->project_id,
                                'ownership_type' => $target->ownership_type,
                            ], $force);

                            $equipmentCount = (int) ($result['equipment_count'] ?? 0);
                            $item->forceFill([
                                'status' => StatisticBackfillItem::STATUS_COMPLETED,
                                'equipment_count' => $equipmentCount,
                                'completed_at' => now(config('app.timezone')),
                                'last_error' => null,
                            ])->save();

                            $summary['calculated']++;
                            $summary['processed_objects'] += $equipmentCount;
                            $ok = true;
                            $this->info("{$date} project {$target->project_id} {$target->ownership_type}: {$equipmentCount} rows.");
                        } catch (Throwable $exception) {
                            $lastError = $exception->getMessage();
                            $item->forceFill([
                                'status' => StatisticBackfillItem::STATUS_FAILED,
                                'last_error' => mb_substr($lastError, 0, 2000),
                                'completed_at' => now(config('app.timezone')),
                            ])->save();

                            $this->warn("{$date} project {$target->project_id} {$target->ownership_type}: attempt {$item->attempts} failed: {$lastError}");
                        }

                        $item->refresh();
                    }

                    if (! $ok) {
                        $summary['failed']++;
                        $this->error("{$date} project {$target->project_id} {$target->ownership_type}: failed after {$item->attempts} attempts.");
                    }

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }
        } finally {
            if (! $dryRun) {
                optional($lock)->release();
            }
        }

        $summary['generator_excluded'] = $this->excludedGeneratorCount();
        $summary['nwc'] = $this->ownershipCount(Equipment::OWNERSHIP_NWC);
        $summary['icare'] = $this->ownershipCount(Equipment::OWNERSHIP_ICARE);
        $summary['seconds'] = round(microtime(true) - $startedAt, 1);

        $this->newLine();
        $this->line('Backfill report');
        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, string $key): array => [$key, $value])->all());

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function targets()
    {
        return ProjectWialonGroup::query()
            ->whereHas('project', fn ($query) => $query->where('active', true))
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('project_id', (int) $projectId))
            ->get(['project_id', 'ownership_type'])
            ->unique(fn (ProjectWialonGroup $group): string => $group->project_id.'|'.$group->ownership_type)
            ->values();
    }

    private function isReady(string $date, int $projectId, string $ownershipType): bool
    {
        if (StatisticBackfillItem::query()
            ->where('stat_date', $date)
            ->where('project_id', $projectId)
            ->where('ownership_type', $ownershipType)
            ->where('status', StatisticBackfillItem::STATUS_COMPLETED)
            ->exists()) {
            return true;
        }

        return $this->existingStatCount($date, $projectId, $ownershipType) > 0;
    }

    private function existingStatCount(string $date, int $projectId, string $ownershipType): int
    {
        return EquipmentDailyStat::query()
            ->where('stat_date', $date)
            ->where('project_id', $projectId)
            ->where('ownership_type', $ownershipType)
            ->where('calculation_source', 'wialon_engine_hours_report')
            ->where('calculation_status', 'success')
            ->count();
    }

    private function markCompleted(string $date, int $projectId, string $ownershipType, int $equipmentCount): void
    {
        StatisticBackfillItem::query()->updateOrCreate(
            [
                'stat_date' => $date,
                'project_id' => $projectId,
                'ownership_type' => $ownershipType,
            ],
            [
                'status' => StatisticBackfillItem::STATUS_COMPLETED,
                'equipment_count' => $equipmentCount,
                'completed_at' => now(config('app.timezone')),
                'last_error' => null,
            ]
        );
    }

    private function excludedGeneratorCount(): int
    {
        return Equipment::query()
            ->where('active', true)
            ->where('dashboard_exclusion_reason', Equipment::DASHBOARD_EXCLUSION_GENERATOR_GROUP)
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('project_id', (int) $projectId))
            ->count();
    }

    private function ownershipCount(string $ownershipType): int
    {
        return Equipment::query()
            ->where('active', true)
            ->visibleInDashboard()
            ->where('ownership_type', $ownershipType)
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('project_id', (int) $projectId))
            ->count();
    }
}
