<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDiagnosticReports;
use App\Services\OperationsDiagnosticService;
use Illuminate\Console\Command;

class DashboardDoctor extends Command
{
    use RendersDiagnosticReports;

    protected $signature = 'dashboard:doctor {--json : Output machine-readable diagnostics}';

    protected $description = 'Run read-only diagnostics for dashboard datasets, routes, cache, and exports.';

    public function handle(OperationsDiagnosticService $diagnostics): int
    {
        return $this->renderDiagnosticReport($diagnostics->dashboardDoctor(), $diagnostics);
    }
}
