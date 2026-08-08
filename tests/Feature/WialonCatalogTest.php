<?php

namespace Tests\Feature;

use App\Jobs\SyncWialonCatalogJob;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Models\WialonCatalogSyncRun;
use App\Services\WialonCatalogSyncService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WialonCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_wialon_catalog_requires_view_permission(): void
    {
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
        ]);
        $catalogViewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
            'permissions' => [User::PERMISSION_WIALON_CATALOG_VIEW],
        ]);

        $this->actingAs($viewer)
            ->get(route('admin.wialon-catalog.index'))
            ->assertForbidden();

        $this->actingAs($catalogViewer)
            ->get(route('admin.wialon-catalog.index'))
            ->assertOk()
            ->assertSee('Wialon kataloqu')
            ->assertDontSee('Hamısını sinxronlaşdır');
    }

    public function test_wialon_catalog_sync_requires_sync_permission_and_queues_job(): void
    {
        Queue::fake();

        $catalogViewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
            'permissions' => [User::PERMISSION_WIALON_CATALOG_VIEW],
        ]);
        $syncUser = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
            'permissions' => [
                User::PERMISSION_WIALON_CATALOG_VIEW,
                User::PERMISSION_WIALON_CATALOG_SYNC,
            ],
        ]);

        $this->actingAs($catalogViewer)
            ->postJson(route('api.wialon-catalog.sync'), ['sections' => ['unit_groups']])
            ->assertForbidden();

        $this->actingAs($syncUser)
            ->postJson(route('api.wialon-catalog.sync'), ['sections' => ['unit_groups']])
            ->assertAccepted()
            ->assertJsonPath('status', WialonCatalogSyncRun::STATUS_QUEUED)
            ->assertJsonPath('sections.0', WialonCatalogSyncService::SECTION_UNIT_GROUPS);

        $this->assertDatabaseHas('wialon_catalog_sync_runs', [
            'status' => WialonCatalogSyncRun::STATUS_QUEUED,
            'started_by' => $syncUser->id,
        ]);
        Queue::assertPushed(SyncWialonCatalogJob::class);
    }

    public function test_wialon_catalog_sync_persists_catalog_without_secrets(): void
    {
        $project = Project::query()->create(['name' => 'Fuzuli project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601',
            'name' => 'Fuzuli NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $type = EquipmentType::query()->create(['name' => 'Dump Truck']);
        Equipment::query()->create([
            'name' => '10-AF-106',
            'wialon_unit_id' => '1001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
            'planned_daily_hours' => 10,
            'active' => true,
        ]);
        Geofence::query()->create([
            'project_id' => $project->id,
            'name' => 'Home geofence',
            'wialon_geofence_id' => '700:900',
            'active' => true,
        ]);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportResources')->once()->andReturn([
                [
                    'id' => 700,
                    'nm' => 'Main report resource',
                    'token' => 'do-not-store',
                    'rep' => [
                        55 => [
                            'id' => 55,
                            'n' => 'Qrup date report Engine hours (api)',
                            'ct' => 'avl_unit_group',
                            'tbl' => [['id' => 1, 'n' => 'Engine hours']],
                        ],
                    ],
                    'zl' => [
                        900 => [
                            'id' => 900,
                            'n' => 'Home geofence',
                            't' => 2,
                            'p' => [['x' => 1, 'y' => 2]],
                            'sid' => 'do-not-store',
                        ],
                    ],
                    'zg' => [
                        12 => [
                            'id' => 12,
                            'n' => 'Project geofence group',
                            'zns' => [900],
                        ],
                    ],
                ],
            ]);
            $mock->shouldReceive('getUnitGroups')->once()->andReturn([
                [
                    'id' => 601,
                    'nm' => 'Fuzuli NWC',
                    'u' => [1001],
                    'password' => 'do-not-store',
                ],
            ]);
            $mock->shouldReceive('getUnits')->once()->with(true)->andReturn([
                [
                    'id' => 1001,
                    'nm' => '10-AF-106',
                    'uid' => 'unit-unique',
                    'flds' => [['n' => 'imei', 'v' => '123456789']],
                ],
            ]);
        });

        $run = WialonCatalogSyncRun::query()->create([
            'uuid' => (string) str()->uuid(),
            'sync_type' => 'test',
            'sections_json' => config('wialon_catalog.sections'),
            'status' => WialonCatalogSyncRun::STATUS_QUEUED,
        ]);

        app(WialonCatalogSyncService::class)->sync($run);

        $this->assertDatabaseHas('wialon_resources', [
            'wialon_resource_id' => '700',
            'name' => 'Main report resource',
            'report_templates_count' => 1,
            'geofences_count' => 1,
            'geofence_groups_count' => 1,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('wialon_unit_groups', [
            'wialon_group_id' => '601',
            'linked_project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'units_count' => 1,
        ]);
        $this->assertDatabaseHas('wialon_units', [
            'wialon_unit_id' => '1001',
            'name' => '10-AF-106',
            'linked_project_id' => $project->id,
            'imei' => '123456789',
        ]);
        $this->assertDatabaseHas('wialon_geofences', [
            'resource_id' => '700',
            'wialon_geofence_id' => '900',
            'linked_project_id' => $project->id,
            'is_home_geofence' => true,
        ]);
        $this->assertDatabaseHas('wialon_report_templates', [
            'resource_id' => '700',
            'wialon_template_id' => '55',
            'usage_status' => 'used',
        ]);
        $this->assertDatabaseHas('wialon_catalog_sync_runs', [
            'id' => $run->id,
            'status' => WialonCatalogSyncRun::STATUS_COMPLETED,
            'error_count' => 0,
        ]);

        $resource = \App\Models\WialonResource::query()->firstOrFail();
        $this->assertSame('[masked]', $resource->raw_metadata_json['token']);
        $geofence = \App\Models\WialonGeofence::query()->firstOrFail();
        $this->assertSame('[masked]', $geofence->raw_metadata_json['sid']);
    }

    public function test_monthly_efficiency_wialon_diagnosis_passes_when_dependencies_exist(): void
    {
        $this->seedMonthlyEfficiencyWialonDependencies();

        $this->artisan('fleet:diagnose-monthly-efficiency-wialon')
            ->expectsOutputToContain('Ready for backend sync implementation')
            ->assertSuccessful();
    }

    public function test_monthly_efficiency_wialon_diagnosis_fails_when_geofence_template_is_missing(): void
    {
        $this->seedMonthlyEfficiencyWialonDependencies(includeGeofenceTemplate: false);

        $this->artisan('fleet:diagnose-monthly-efficiency-wialon')
            ->expectsOutputToContain('Missing: geofence_template')
            ->assertExitCode(1);
    }

    public function test_projects_manage_permission_allows_project_index_without_full_admin(): void
    {
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
            'permissions' => [User::PERMISSION_PROJECTS_MANAGE],
        ]);

        $this->actingAs($viewer)
            ->get(route('projects.index'))
            ->assertOk();
    }

    private function seedMonthlyEfficiencyWialonDependencies(bool $includeGeofenceTemplate = true): void
    {
        \Illuminate\Support\Facades\DB::table('wialon_resources')->insert([
            'wialon_resource_id' => '601701680',
            'name' => 'Main report resource',
            'report_templates_count' => 2,
            'geofences_count' => 31,
            'geofence_groups_count' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('wialon_report_templates')->insert([
            [
                'resource_id' => '601701680',
                'wialon_template_id' => '19',
                'name' => 'Qrup report Engine hours (api)',
                'report_type' => 'avl_unit_group',
                'usage_status' => 'used',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ...($includeGeofenceTemplate ? [[
                'resource_id' => '601701680',
                'wialon_template_id' => '119',
                'name' => 'Aylıq effektivlik Engine hours (api)',
                'report_type' => 'avl_unit_group',
                'usage_status' => 'used',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]] : []),
        ]);

        \Illuminate\Support\Facades\DB::table('wialon_geofence_groups')->insert([
            'resource_id' => '601701680',
            'wialon_geofence_group_id' => '31',
            'name' => 'Aylıq effektivlik üçün',
            'geofences_count' => 31,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
