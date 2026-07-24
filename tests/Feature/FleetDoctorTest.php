<?php

namespace Tests\Feature;

use App\Models\Equipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOperationsDiagnosticData;
use Tests\TestCase;

class FleetDoctorTest extends TestCase
{
    use CreatesOperationsDiagnosticData;
    use RefreshDatabase;

    public function test_fleet_doctor_is_read_only_and_reports_fleet_checks(): void
    {
        $this->seedOperationsDiagnosticData();
        $equipmentCount = Equipment::count();

        $result = $this->runDiagnosticCommand('fleet:doctor');

        $this->assertLessThan(2, $result['exit_code']);
        $this->assertSame($equipmentCount, Equipment::count());
        $this->assertContains('fleet.positions', array_column($result['payload']['checks'], 'key'));
        $this->assertContains('fleet.position_timestamps', array_column($result['payload']['checks'], 'key'));
    }
}
