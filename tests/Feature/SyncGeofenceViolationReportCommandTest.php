<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncGeofenceViolationReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_uses_a_dedicated_wialon_session(): void
    {
        $project = Project::create(['name' => 'Test project', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701886',
            'name' => 'Test project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('dedicated-session');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('dedicated-session');
        $wialon->shouldReceive('executeReport')
            ->once()
            ->with(
                601701680,
                22,
                '601701886',
                Mockery::type('int'),
                Mockery::type('int'),
                0,
                'dedicated-session',
                false,
                60
            )
            ->andReturn([
                'reportResult' => [
                    'tables' => [[
                        'name' => 'unit_group_zones_visit',
                        'label' => 'Geofences',
                        'rows' => 0,
                        'level' => 2,
                    ]],
                ],
            ]);
        $wialon->shouldReceive('logoutSession')->once()->with('dedicated-session');
        $wialon->shouldNotReceive('getReportTablesRows');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601701886',
        ])->assertSuccessful();
    }
}
