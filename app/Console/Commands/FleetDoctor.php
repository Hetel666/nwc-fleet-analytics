<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDiagnosticReports;
use App\Services\OperationsDiagnosticService;
use Illuminate\Console\Command;

class FleetDoctor extends Command
{
    use RendersDiagnosticReports;

    protected $signature = 'fleet:doctor {--json : Output machine-readable diagnostics}';

    protected $description = 'Run read-only diagnostics for fleet synchronization and position freshness.';

    public function handle(OperationsDiagnosticService $diagnostics): int
    {
        return $this->renderDiagnosticReport($diagnostics->fleetDoctor(), $diagnostics);
    }
}
