<?php

namespace Tests\Feature;

use App\Models\Equipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOperationsDiagnosticData;
use Tests\TestCase;

class DashboardDoctorTest extends TestCase
{
    use CreatesOperationsDiagnosticData;
    use RefreshDatabase;

    public function test_dashboard_doctor_is_read_only_and_reports_dashboard_checks(): void
    {
        $this->seedOperationsDiagnosticData();
        $equipmentCount = Equipment::count();

        $result = $this->runDiagnosticCommand('dashboard:doctor');

        $this->assertLessThan(2, $result['exit_code']);
        $this->assertSame($equipmentCount, Equipment::count());
        $this->assertContains('dashboard.ownership', array_column($result['payload']['checks'], 'key'));
        $this->assertContains('dashboard.geofence', array_column($result['payload']['checks'], 'key'));
    }
}
