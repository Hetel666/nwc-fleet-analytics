<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOperationsDiagnosticData;
use Tests\TestCase;

class ReadyCommandTest extends TestCase
{
    use CreatesOperationsDiagnosticData;
    use RefreshDatabase;

    public function test_system_ready_outputs_json_without_critical_failures(): void
    {
        $this->seedOperationsDiagnosticData();

        $this->assertNoCriticalFailures('system:ready');
    }
}
