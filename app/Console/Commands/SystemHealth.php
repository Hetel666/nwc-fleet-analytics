<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDiagnosticReports;
use App\Services\OperationsDiagnosticService;
use Illuminate\Console\Command;

class SystemHealth extends Command
{
    use RendersDiagnosticReports;

    protected $signature = 'system:health {--json : Output machine-readable diagnostics}';

    protected $description = 'Run read-only production health checks for the application runtime.';

    public function handle(OperationsDiagnosticService $diagnostics): int
    {
        return $this->renderDiagnosticReport($diagnostics->systemHealth(), $diagnostics);
    }
}
