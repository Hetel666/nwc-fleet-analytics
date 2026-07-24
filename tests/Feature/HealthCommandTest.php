<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOperationsDiagnosticData;
use Tests\TestCase;

class HealthCommandTest extends TestCase
{
    use CreatesOperationsDiagnosticData;
    use RefreshDatabase;

    public function test_system_health_outputs_machine_readable_diagnostics(): void
    {
        $this->seedOperationsDiagnosticData();

        $result = $this->runDiagnosticCommand('system:health');

        $this->assertContains('php.version', array_column($result['payload']['checks'], 'key'));
        $this->assertContains('database.connection', array_column($result['payload']['checks'], 'key'));
    }
}
