<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\User;
use App\Services\ForeignProjectGeofenceMonitoringService;
use App\Services\GeofenceViolationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardModalConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_geofence_modal_endpoint_matches_dashboard_summary_service(): void
    {
        [$home, $foreign] = $this->createGeofenceViolation();
        Carbon::setTestNow('2026-07-17 13:00:00');
        $filters = [
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
            'geofence_violation' => true,
            'current_geozone_project_id' => $foreign->id,
        ];
        $expectedTotal = app(GeofenceViolationService::class)->summary($filters)['total'];

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]))
            ->getJson(route('dashboard.drilldown.units', $filters))
            ->assertOk()
            ->assertJsonPath('summary.total', $expectedTotal)
            ->assertJsonPath('data.0.home_project', $home->name)
            ->assertJsonPath('data.0.current_project', $foreign->name);
    }

    private function createGeofenceViolation(): array
    {
        $home = Project::query()->create(['name' => 'Home Project', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign Project', 'active' => true]);
        $this->geofence($home, 'Home Project', 0, 0, 10, 10);
        $this->geofence($foreign, 'Foreign Project', 20, 20, 30, 30);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $unit = Equipment::query()->create([
            'name' => 'Geo Unit',
            'wialon_unit_id' => 'geo-1',
            'equipment_type_id' => $type->id,
            'project_id' => $home->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701935',
            'active' => true,
        ])->load('type');

        $monitoring = app(ForeignProjectGeofenceMonitoringService::class);
        $monitoring->processUnitPosition($unit, ['lat' => 25, 'lng' => 25, 'time' => '2026-07-17 09:00:00']);
        $monitoring->processUnitPosition($unit, ['lat' => 25, 'lng' => 25, 'time' => '2026-07-17 13:00:00']);

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
}
