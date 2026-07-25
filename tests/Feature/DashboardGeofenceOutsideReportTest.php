<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGeofenceOutsideReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_geofence_outside_rows_are_read_from_prepared_local_data(): void
    {
        config([
            'fleet.wialon.geofence_outside_report_resource_id' => 601701680,
            'fleet.wialon.geofence_outside_report_template_id' => 12,
            'fleet.wialon.live_dashboard_reports' => true,
        ]);

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $nwcA = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC A');
        $nwcB = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC B');
        $icareA = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE A');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '602',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $geofence = Geofence::create([
            'project_id' => $project->id,
            'name' => 'LOT3',
            'wialon_geofence_id' => '601701680:187',
            'geometry_json' => [],
            'active' => true,
        ]);

        $this->event($project, $geofence, $nwcA, '2026-07-03 10:00:00', 390);
        $this->event($project, $geofence, $icareA, '2026-07-02 10:00:00', 180);
        $this->event($project, $geofence, $nwcB, '2026-07-01 10:00:00', 0);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

        $rows = app(DashboardService::class)->getGeofenceOutsideRows([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-08',
        ], null);

        $this->assertSame([
            [
                'grouping' => 'NWC A',
                'vendor' => 'NWC',
                'outside_hours' => 6.5,
            ],
            [
                'grouping' => 'ICARE A',
                'vendor' => 'ICARE',
                'outside_hours' => 3.0,
            ],
            [
                'grouping' => 'NWC B',
                'vendor' => 'NWC',
                'outside_hours' => 0.0,
            ],
        ], $rows);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownershipType,
            'matched_wialon_group_id' => $ownershipType === Equipment::OWNERSHIP_ICARE ? '602' : '601',
            'active' => true,
        ]);
    }

    private function event(Project $project, Geofence $geofence, Equipment $equipment, string $exitAt, int $outsideMinutes): void
    {
        GeofenceEvent::create([
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'geofence_id' => $geofence->id,
            'exit_at' => $exitAt,
            'return_at' => null,
            'outside_minutes' => $outsideMinutes,
            'max_distance_meters' => 0,
            'status' => 'outside',
        ]);
    }
}
