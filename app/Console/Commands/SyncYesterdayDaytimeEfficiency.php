<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Services\DashboardReportPipelineService;
use App\Services\HistoricalRecalculationService;
use Illuminate\Console\Command;

class SyncYesterdayDaytimeEfficiency extends Command
{
    protected $signature = 'daytime-efficiency:sync-yesterday';

    protected $description = 'Queue the daytime efficiency report for the previous Baku calendar day.';

    public function handle(HistoricalRecalculationService $service, DashboardReportPipelineService $pipelines): int
    {
        $timezone = (string) config('historical_recalculation.timezone', 'Asia/Baku');
        $date = now($timezone)->subDay()->toDateString();
        $active = HistoricalRecalculation::query()
            ->where('dashboard_section', HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY)
            ->whereDate('date_from', $date)
            ->whereDate('date_to', $date)
            ->whereIn('status', [
                HistoricalRecalculation::STATUS_PENDING,
                HistoricalRecalculation::STATUS_RUNNING,
            ])
            ->first();

        if ($active) {
            $this->line("Daytime efficiency run {$active->id} is already active for {$date}.");

            return self::SUCCESS;
        }

        $plan = [
            'date_from' => $date,
            'date_to' => $date,
            'timezone' => $timezone,
            'dashboard_section' => HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'project_ids' => [],
            'force' => true,
        ];
        $preview = $service->preview($plan);
        $result = $pipelines->queue([$plan], 'manual', $pipelines->priorityForSource('manual'));

        $this->line(sprintf(
            'Daytime efficiency pipeline %s queued for %s with %d tasks. Started run: %s.',
            is_array($result['pipeline'] ?? null) ? ($result['pipeline']['id'] ?? '-') : '-',
            $date,
            (int) ($preview['total_tasks'] ?? 0),
            $result['started_run_id'] ?? '-'
        ));

        return self::SUCCESS;
    }
}
