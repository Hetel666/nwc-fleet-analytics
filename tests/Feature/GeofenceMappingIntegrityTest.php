<?php

namespace Tests\Feature;

use App\Models\Geofence;
use App\Models\Project;
use App\Models\User;
use App\Models\WialonGeofence;
use App\Models\WialonUnitGroup;
use App\Services\WialonCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceMappingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_mapping_cannot_steal_another_projects_home_zone(): void
    {
        $owner = Project::create(['name' => 'Zone owner', 'active' => true]);
        $target = Project::create(['name' => 'Target', 'active' => true]);
        $local = Geofence::create(['project_id' => $owner->id, 'name' => 'Home', 'wialon_geofence_id' => '700:9', 'active' => true]);
        $zone = WialonGeofence::create(['resource_id' => '700', 'wialon_geofence_id' => '9', 'name' => 'Home', 'is_active' => true]);
        WialonUnitGroup::create(['wialon_group_id' => '800', 'name' => 'Target - NWC', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]))
            ->putJson(route('api.projects.wialon-mapping.update', $target), ['wialon_group_id' => '800', 'ownership_type' => 'NWC', 'home_geofence_id' => $zone->id])
            ->assertUnprocessable();
        $this->assertSame($owner->id, $local->refresh()->project_id);
    }

    public function test_manual_zone_requires_resource_and_zone_ids(): void
    {
        $project = Project::create(['name' => 'Project', 'active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->postJson(route('geofences.store'), ['name' => 'Home', 'project_id' => $project->id, 'wialon_geofence_id' => '700', 'active' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('wialon_geofence_id');
    }

    public function test_catalog_does_not_link_inactive_or_resource_ambiguous_zone(): void
    {
        $project = Project::create(['name' => 'Project', 'active' => true]);
        $inactive = new Geofence(['project_id' => $project->id, 'wialon_geofence_id' => '700:9', 'active' => false]);
        $legacy = new Geofence(['project_id' => $project->id, 'wialon_geofence_id' => '9', 'active' => true]);
        $method = new \ReflectionMethod(WialonCatalogSyncService::class, 'matchingLocalGeofence');
        $service = app(WialonCatalogSyncService::class);
        $this->assertNull($method->invoke($service, collect([$inactive, $legacy]), '700', '9'));
        $this->assertNull($method->invoke($service, collect([$legacy]), '701', '9'));
    }
}
