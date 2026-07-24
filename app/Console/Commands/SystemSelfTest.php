<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDiagnosticReports;
use App\Services\OperationsDiagnosticService;
use Illuminate\Console\Command;

class SystemSelfTest extends Command
{
    use RendersDiagnosticReports;

    protected $signature = 'system:self-test {--json : Output machine-readable diagnostics}';

    protected $description = 'Run all read-only operational diagnostics and return one aggregate result.';

    public function handle(OperationsDiagnosticService $diagnostics): int
    {
        return $this->renderDiagnosticReport($diagnostics->selfTest(), $diagnostics);
    }
}
