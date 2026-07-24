<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDiagnosticReports;
use App\Services\OperationsDiagnosticService;
use Illuminate\Console\Command;

class SystemReady extends Command
{
    use RendersDiagnosticReports;

    protected $signature = 'system:ready {--json : Output machine-readable diagnostics}';

    protected $description = 'Run read-only deployment readiness checks.';

    public function handle(OperationsDiagnosticService $diagnostics): int
    {
        return $this->renderDiagnosticReport($diagnostics->readiness(), $diagnostics);
    }
}
