<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDiagnosticReports;
use App\Services\OperationsDiagnosticService;
use Illuminate\Console\Command;

class GeofenceDoctor extends Command
{
    use RendersDiagnosticReports;

    protected $signature = 'geofence:doctor {--json : Output machine-readable diagnostics}';

    protected $description = 'Run read-only diagnostics for foreign geofence monitoring intervals.';

    public function handle(OperationsDiagnosticService $diagnostics): int
    {
        return $this->renderDiagnosticReport($diagnostics->geofenceDoctor(), $diagnostics);
    }
}
