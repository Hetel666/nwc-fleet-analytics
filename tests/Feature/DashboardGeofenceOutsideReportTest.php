<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGeofenceOutsideReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_geofence_outside_rows_are_loaded_from_local_events_without_wialon(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $nwcA = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC A');
        $nwcB = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC B');
        $icareA = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE A');

        $this->event($project, $nwcB, '2026-07-03 09:00:00', 0);
        $this->event($project, $icareA, '2026-07-04 09:00:00', 180);
        $this->event($project, $nwcA, '2026-07-05 09:00:00', 390);

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

    private function event(Project $project, Equipment $equipment, string $exitAt, int $outsideMinutes): GeofenceEvent
    {
        return GeofenceEvent::create([
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'exit_at' => $exitAt,
            'outside_minutes' => $outsideMinutes,
            'status' => 'outside',
        ]);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownershipType,
        ]);
    }
}
