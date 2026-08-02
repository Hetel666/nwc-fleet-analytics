<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Services\DashboardReportPipelineService;
use App\Services\HistoricalRecalculationService;
use Illuminate\Console\Command;

class QueueEfficiencySync extends Command
{
    protected $signature = 'fleet:queue-efficiency-sync
        {--from= : First business date}
        {--to= : Last business date}
        {--force : Refresh existing facts}';

    protected $description = 'Queue the canonical Engine hours efficiency synchronization.';

    public function handle(HistoricalRecalculationService $service, DashboardReportPipelineService $pipelines): int
    {
        $from = $this->option('from') ?: now(config('app.timezone'))->subDay()->toDateString();
        $to = $this->option('to') ?: $from;
        $plan = [
            'date_from' => $from,
            'date_to' => $to,
            'timezone' => config('historical_recalculation.timezone', 'Asia/Baku'),
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'project_ids' => [],
            'force' => (bool) $this->option('force'),
        ];
        $preview = $service->preview($plan);
        $result = $pipelines->queue([$plan], 'manual', $pipelines->priorityForSource('manual'));

        $this->line(sprintf(
            'Efficiency pipeline %s queued with %d tasks. Started run: %s.',
            is_array($result['pipeline'] ?? null) ? ($result['pipeline']['id'] ?? '-') : '-',
            (int) ($preview['total_tasks'] ?? 0),
            $result['started_run_id'] ?? '-'
        ));

        return self::SUCCESS;
    }
}
