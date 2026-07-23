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
use RuntimeException;
use Tests\TestCase;

class DashboardGeofenceOutsideReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_geofence_outside_rows_are_loaded_from_local_events_without_wialon_calls(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        $geofence = Geofence::create(['name' => 'LOT3', 'project_id' => $project->id, 'active' => true]);

        $nwc = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC A');
        $icare = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE A');

        GeofenceEvent::create([
            'equipment_id' => $nwc->id,
            'project_id' => $project->id,
            'geofence_id' => $geofence->id,
            'exit_at' => '2026-07-01 10:00:00',
            'return_at' => '2026-07-01 13:30:00',
            'outside_minutes' => 210,
        ]);
        GeofenceEvent::create([
            'equipment_id' => $icare->id,
            'project_id' => $project->id,
            'geofence_id' => $geofence->id,
            'exit_at' => '2026-07-02 10:00:00',
            'return_at' => '2026-07-02 11:00:00',
            'outside_minutes' => 60,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct() {}

            public function getReportTablesRows(
                int|string $resourceId,
                int|string $templateId,
                int|string $objectId,
                int $from,
                int $to,
                int $chunkSize = 500,
                int $intervalFlags = 0,
                bool $remoteExec = false,
                ?int $requestTimeout = null
            ): array {
                throw new RuntimeException('Dashboard must not call Wialon.');
            }
        });

        $rows = app(DashboardService::class)->getGeofenceOutsideRows([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-08',
        ], null);

        $this->assertSame([
            [
                'grouping' => 'ICARE A',
                'vendor' => 'ICARE',
                'outside_hours' => 1.0,
            ],
            [
                'grouping' => 'NWC A',
                'vendor' => 'NWC',
                'outside_hours' => 3.5,
            ],
        ], $rows);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        $group = ProjectWialonGroup::query()->firstOrCreate(
            [
                'project_id' => $project->id,
                'ownership_type' => $ownershipType,
            ],
            [
                'wialon_group_id' => 'group-'.$project->id.'-'.$ownershipType,
                'name' => $project->name.' '.$ownershipType,
            ]
        );

        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => $ownershipType,
            'active' => true,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
        ]);
    }
}
