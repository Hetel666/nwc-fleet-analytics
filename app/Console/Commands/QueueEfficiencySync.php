<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Services\HistoricalRecalculationService;
use Illuminate\Console\Command;

class QueueEfficiencySync extends Command
{
    protected $signature = 'fleet:queue-efficiency-sync
        {--from= : First business date}
        {--to= : Last business date}
        {--force : Refresh existing facts}';

    protected $description = 'Queue the canonical Engine hours efficiency synchronization.';

    public function handle(HistoricalRecalculationService $service): int
    {
        $from = $this->option('from') ?: now(config('app.timezone'))->subDay()->toDateString();
        $to = $this->option('to') ?: $from;
        $run = $service->createRun([
            'date_from' => $from,
            'date_to' => $to,
            'timezone' => config('historical_recalculation.timezone', 'Asia/Baku'),
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'project_ids' => [],
            'force' => (bool) $this->option('force'),
        ], null);

        $this->line("Efficiency run {$run->id} queued with {$run->total_tasks} tasks.");

        return self::SUCCESS;
    }
}
