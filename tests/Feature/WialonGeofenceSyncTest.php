<?php

namespace Tests\Feature;

use App\Models\Geofence;
use App\Models\Project;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WialonGeofenceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_geofence_ids_are_the_only_active_wialon_geofences(): void
    {
        config([
            'wialon_projects.project_geofence_ids' => [
                'Project A' => ['601701680:7'],
                'Project B' => ['601701680:8', '601701680:9'],
                'Project without geofence' => [],
            ],
        ]);

        $projectA = Project::create(['name' => 'Project A', 'active' => true]);
        $projectB = Project::create(['name' => 'Project B', 'active' => true]);
        Project::create(['name' => 'Project without geofence', 'active' => true]);

        Geofence::create([
            'project_id' => $projectA->id,
            'name' => 'Old zone',
            'normalized_name' => 'old zone',
            'wialon_geofence_id' => '601701680:999',
            'geometry_json' => ['type' => 'Polygon', 'coordinates' => []],
            'active' => true,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getGeofenceZonesByIds(int|string $resourceId, array $zoneIds): array
            {
                return array_map(
                    fn (int|string $zoneId): array => [
                        'id' => (int) $zoneId,
                        'n' => 'Zone '.$zoneId,
                        't' => 2,
                        'p' => [
                            ['x' => 48.1, 'y' => 40.1],
                            ['x' => 48.2, 'y' => 40.1],
                            ['x' => 48.2, 'y' => 40.2],
                            ['x' => 48.1, 'y' => 40.2],
                        ],
                    ],
                    $zoneIds
                );
            }
        });

        $this->artisan('fleet:sync-geofences')
            ->expectsOutput('Synced 3 Wialon geofences.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('geofences', [
            'wialon_geofence_id' => '601701680:7',
            'project_id' => $projectA->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('geofences', [
            'wialon_geofence_id' => '601701680:8',
            'project_id' => $projectB->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('geofences', [
            'wialon_geofence_id' => '601701680:9',
            'project_id' => $projectB->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('geofences', [
            'wialon_geofence_id' => '601701680:999',
            'active' => false,
        ]);
    }
}
