<?php

namespace Tests\Feature;

use App\Models\UnitForeignGeofenceInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOperationsDiagnosticData;
use Tests\TestCase;

class GeofenceDoctorTest extends TestCase
{
    use CreatesOperationsDiagnosticData;
    use RefreshDatabase;

    public function test_geofence_doctor_is_read_only_and_reports_interval_checks(): void
    {
        $this->seedOperationsDiagnosticData();
        $intervalCount = UnitForeignGeofenceInterval::count();

        $result = $this->runDiagnosticCommand('geofence:doctor');

        $this->assertLessThan(2, $result['exit_code']);
        $this->assertSame($intervalCount, UnitForeignGeofenceInterval::count());
        $this->assertContains('geofence.duplicate_open_intervals', array_column($result['payload']['checks'], 'key'));
        $this->assertContains('geofence.stale_intervals', array_column($result['payload']['checks'], 'key'));
    }
}
