<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Services\DashboardReportPipelineService;
use App\Services\HistoricalRecalculationService;
use Illuminate\Console\Command;

class SyncLastCompletedNighttimeEfficiency extends Command
{
    protected $signature = 'nighttime-efficiency:sync-last-completed-shift {--force}';

    protected $description = 'Queue the last completed 18:00-07:59 Asia/Baku efficiency shift.';

    public function handle(HistoricalRecalculationService $service, DashboardReportPipelineService $pipelines): int
    {
        $timezone = (string) config('historical_recalculation.timezone', 'Asia/Baku');
        $shiftDate = now($timezone)->subDay()->toDateString();
        $query = HistoricalRecalculation::query()
            ->where('dashboard_section', HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY)
            ->whereDate('date_from', $shiftDate)
            ->whereDate('date_to', $shiftDate);
        $active = (clone $query)->whereIn('status', [
            HistoricalRecalculation::STATUS_PENDING,
            HistoricalRecalculation::STATUS_RUNNING,
        ])->latest()->first();

        if ($active) {
            $this->line("Nighttime efficiency run {$active->id} is already active for shift {$shiftDate}.");

            return self::SUCCESS;
        }

        $completed = (clone $query)->whereIn('status', [
            HistoricalRecalculation::STATUS_COMPLETED,
            HistoricalRecalculation::STATUS_COMPLETED_WITH_ERRORS,
        ])->latest()->first();

        if ($completed && ! $this->option('force')) {
            $this->line("Nighttime efficiency run {$completed->id} already covers shift {$shiftDate}.");

            return self::SUCCESS;
        }

        $plan = [
            'date_from' => $shiftDate,
            'date_to' => $shiftDate,
            'section' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'project_ids' => [],
            'force' => true,
        ];
        $preview = $service->preview([
            'date_from' => $shiftDate,
            'date_to' => $shiftDate,
            'timezone' => $timezone,
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'project_ids' => [],
            'force' => true,
        ]);
        $result = $pipelines->queue([$plan], 'nightly', $pipelines->priorityForSource('nightly'));

        $this->line(sprintf(
            'Nighttime efficiency pipeline %s queued for shift %s with %d tasks. Started run: %s.',
            is_array($result['pipeline'] ?? null) ? ($result['pipeline']['id'] ?? '-') : '-',
            $shiftDate,
            (int) ($preview['total_tasks'] ?? 0),
            $result['started_run_id'] ?? '-'
        ));

        return self::SUCCESS;
    }
}
