<?php

namespace App\Console\Commands;

use App\Services\DashboardReportPipelineService;
use Illuminate\Console\Command;

class TickDashboardReportPipeline extends Command
{
    protected $signature = 'dashboard-reports:pipeline-tick';

    protected $description = 'Resume the next dashboard report pipeline step from the stored checkpoint.';

    public function handle(DashboardReportPipelineService $pipelines): int
    {
        $result = $pipelines->tick();

        $this->line(sprintf(
            'Pipeline tick: %s; started run: %s',
            $result['status'] ?? '-',
            $result['started_run_id'] ?? '-'
        ));

        return self::SUCCESS;
    }
}
