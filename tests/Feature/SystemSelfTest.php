<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOperationsDiagnosticData;
use Tests\TestCase;

class SystemSelfTest extends TestCase
{
    use CreatesOperationsDiagnosticData;
    use RefreshDatabase;

    public function test_system_self_test_combines_all_diagnostic_sections(): void
    {
        $this->seedOperationsDiagnosticData();

        $result = $this->runDiagnosticCommand('system:self-test');

        $this->assertContains('SYSTEM HEALTH', array_column($result['payload']['checks'], 'section'));
        $this->assertContains('DASHBOARD DOCTOR', array_column($result['payload']['checks'], 'section'));
        $this->assertContains('GEOFENCE DOCTOR', array_column($result['payload']['checks'], 'section'));
    }
}
