<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWialonReportStats extends Command
{
    protected $signature = 'fleet:sync-report-stats
        {--date= : Single date in YYYY-MM-DD format}
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--project= : Project database ID}
        {--ownership= : NWC or ICARE}
        {--force : Rebuild dates already synchronized successfully}';

    protected $description = 'Store daily Engine hours and Mileage from Wialon group reports.';

    public function handle(DashboardService $dashboard): int
    {
        $from = Carbon::parse($this->option('date') ?: $this->option('from') ?: now(config('app.timezone'))->subDay())
            ->toDateString();
        $to = Carbon::parse($this->option('date') ?: $this->option('to') ?: $from)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $ownershipType = strtoupper(trim((string) $this->option('ownership')));

        if ($ownershipType !== '' && ! in_array($ownershipType, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $this->error('Ownership must be NWC or ICARE.');

            return self::INVALID;
        }

        $targets = ProjectWialonGroup::query()
            ->whereHas('project', fn ($query) => $query->where('active', true))
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('project_id', (int) $projectId))
            ->when($ownershipType !== '', fn ($query) => $query->where('ownership_type', $ownershipType))
            ->get(['project_id', 'ownership_type'])
            ->unique(fn (ProjectWialonGroup $group): string => $group->project_id.'|'.$group->ownership_type)
            ->values();

        if ($targets->isEmpty()) {
            $this->warn('No matching project Wialon groups found.');

            return self::SUCCESS;
        }

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($targets as $target) {
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $dateString = $date->toDateString();

                try {
                    $result = $dashboard->syncDailyEngineHoursReport([
                        'date_from' => $dateString,
                        'date_to' => $dateString,
                        'project_id' => $target->project_id,
                        'ownership_type' => $target->ownership_type,
                    ], (bool) $this->option('force'));

                    if ($result['status'] === 'skipped') {
                        $skipped++;
                        $this->line("{$dateString} project {$target->project_id} {$target->ownership_type}: already synchronized.");
                    } else {
                        $synced++;
                        $this->info("{$dateString} project {$target->project_id} {$target->ownership_type}: {$result['equipment_count']} equipment rows.");
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("{$dateString} project {$target->project_id} {$target->ownership_type}: {$exception->getMessage()}");
                    Log::warning('Wialon report stats synchronization failed', [
                        'date' => $dateString,
                        'project_id' => $target->project_id,
                        'ownership_type' => $target->ownership_type,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->line("Report stats sync finished: {$synced} synced, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
