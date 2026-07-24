<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Services\DashboardService;
use App\Services\ForeignProjectGeofenceMonitoringService;
use App\Services\WialonService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGeofenceOutsideReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.foreign_geofence.min_minutes' => 180,
            'fleet.foreign_geofence.stale_after_minutes' => 30,
            'fleet.foreign_geofence.show_all' => false,
            'fleet.foreign_geofence.include_stale' => false,
        ]);
    }

    public function test_geofence_outside_rows_are_loaded_from_current_intervals_without_wialon(): void
    {
        [$home, $foreign] = $this->projectsWithGeofences();
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $nwc = $this->equipment($home, $type, Equipment::OWNERSHIP_NWC, 'NWC A');
        $icare = $this->equipment($home, $type, Equipment::OWNERSHIP_ICARE, 'ICARE A');
        $monitoring = app(ForeignProjectGeofenceMonitoringService::class);

        $monitoring->processUnitPosition($nwc, $this->position(25, 25, '2026-07-17 09:00:00'));
        $monitoring->processUnitPosition($nwc, $this->position(25, 25, '2026-07-17 13:00:00'));
        $monitoring->processUnitPosition($icare, $this->position(25, 25, '2026-07-17 10:00:00'));
        $monitoring->processUnitPosition($icare, $this->position(25, 25, '2026-07-17 13:30:00'));
        Carbon::setTestNow('2026-07-17 13:30:00');

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
            $mock->shouldReceive('findReportTemplateIdByName')->never();
            $mock->shouldReceive('getMessages')->never();
        });

        $rows = app(DashboardService::class)->getGeofenceOutsideRows([
            'project_id' => $home->id,
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
        ], null);

        $this->assertSame([
            [
                'grouping' => 'NWC A',
                'vendor' => 'NWC',
                'outside_hours' => 4.0,
                'current_project' => $foreign->name,
                'current_geofence' => $foreign->name,
            ],
            [
                'grouping' => 'ICARE A',
                'vendor' => 'ICARE',
                'outside_hours' => 3.5,
                'current_project' => $foreign->name,
                'current_geofence' => $foreign->name,
            ],
        ], $rows);
    }

    /**
     * @return array{Project, Project}
     */
    private function projectsWithGeofences(): array
    {
        $home = Project::query()->create(['name' => 'Home Project', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign Project', 'active' => true]);

        $this->geofence($home, 'Home Project', 0, 0, 10, 10);
        $this->geofence($foreign, 'Foreign Project', 20, 20, 30, 30);

        return [$home, $foreign];
    }

    private function geofence(Project $project, string $name, float $minLng, float $minLat, float $maxLng, float $maxLat): Geofence
    {
        return Geofence::query()->create([
            'name' => $name,
            'project_id' => $project->id,
            'wialon_geofence_id' => uniqid('zone-', true),
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$minLng, $minLat],
                    [$maxLng, $minLat],
                    [$maxLng, $maxLat],
                    [$minLng, $maxLat],
                    [$minLng, $minLat],
                ]],
            ],
            'active' => true,
        ]);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownershipType,
            'matched_wialon_group_id' => '601701935',
            'active' => true,
        ])->load('type');
    }

    /**
     * @return array{lat: float, lng: float, time: string}
     */
    private function position(float $lng, float $lat, string $time): array
    {
        return ['lat' => $lat, 'lng' => $lng, 'time' => $time];
    }
}
