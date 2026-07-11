<?php

namespace Tests\Feature;

use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGeofenceGroup;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WialonGeofenceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_geofences_are_imported_from_mapped_wialon_group(): void
    {
        $project = Project::create(['name' => 'Yuxarı Şirvan LOT3', 'active' => true]);

        ProjectWialonGeofenceGroup::create([
            'project_id' => $project->id,
            'wialon_resource_id' => '601701680',
            'wialon_resource_name' => 'NWCuser2',
            'wialon_geofence_group_id' => '3',
            'name' => 'M00 LOT-3',
            'zones_count' => 4,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getGeofenceGroupZones(int|string $resourceId, int|string $groupId): array
            {
                return [
                    [
                        'id' => 22,
                        'n' => 'M001 Karxana LOT-3',
                        't' => 2,
                        'p' => [
                            ['x' => 48.1, 'y' => 40.1],
                            ['x' => 48.2, 'y' => 40.1],
                            ['x' => 48.2, 'y' => 40.2],
                            ['x' => 48.1, 'y' => 40.2],
                        ],
                    ],
                ];
            }
        });

        $this->artisan('fleet:sync-geofences')
            ->expectsOutput('Synced 1 Wialon geofences.')
            ->assertExitCode(0);

        $geofence = Geofence::firstOrFail();

        $this->assertSame('M001 Karxana LOT-3', $geofence->name);
        $this->assertSame($project->id, $geofence->project_id);
        $this->assertSame('601701680:22', $geofence->wialon_geofence_id);
        $this->assertSame('Polygon', $geofence->geometry_json['type']);
    }
}
