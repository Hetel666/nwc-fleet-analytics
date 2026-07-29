<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\GeofenceViolationReportRow;
use App\Models\GeofenceViolationSyncItem;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\WialonService;
use Carbon\CarbonImmutable;
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

        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'wialon_group_id' => '601701886',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
            'source_rows' => 0,
            'imported_rows' => 0,
        ]);
    }

    public function test_invalid_aggregated_row_rolls_back_the_whole_group_snapshot(): void
    {
        $project = Project::create(['name' => 'Atomic project', 'active' => true]);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701999',
            'name' => 'Atomic project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        foreach (['601700100' => '10-AF-100', '601700101' => '10-AF-101'] as $id => $name) {
            Equipment::create([
                'name' => $name,
                'wialon_unit_id' => $id,
                'equipment_type_id' => $type->id,
                'project_id' => $project->id,
                'project_wialon_group_id' => $group->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'active' => true,
            ]);
        }

        $entry = CarbonImmutable::parse('2026-07-28 10:00:00', 'Asia/Baku')->timestamp;
        $validExit = CarbonImmutable::parse('2026-07-28 14:00:00', 'Asia/Baku')->timestamp;
        $aggregateExit = CarbonImmutable::parse('2026-07-28 16:00:00', 'Asia/Baku')->timestamp;
        $rootRow = [
            'c' => ['Out of geofences', '', '', '', '', ''],
            'r' => [
                $this->unitRow('10-AF-100', '601700100', $entry, $validExit, '4:00:00'),
                $this->unitRow('10-AF-101', '601700101', $entry, $aggregateExit, '4:00:00'),
            ],
        ];
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('atomic-session');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('atomic-session');
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => [
                'tables' => [[
                    'header' => ['Grouping', 'Type', 'geofence', 'Entry', 'Exit', 'Duration'],
                    'header_type' => ['', 'user_column', 'zone_name', 'time_begin', 'time_end', 'duration_in'],
                    'rows' => 1,
                    'level' => 2,
                ]],
            ],
        ]);
        $wialon->shouldReceive('selectReportResultRows')
            ->once()
            ->with(0, Mockery::type('array'), 'atomic-session')
            ->andReturn([$rootRow]);
        $wialon->shouldReceive('logoutSession')->once()->with('atomic-session');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601701999',
            '--force' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('geofence_violation_report_rows', 0);
        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'project_wialon_group_id' => $group->id,
            'status' => GeofenceViolationSyncItem::STATUS_FAILED,
            'last_error_code' => 'NON_CONTINUOUS_INTERVALS',
            'imported_rows' => 0,
            'rejected_rows' => 1,
        ]);
    }

    public function test_wialon_response_without_tables_is_a_valid_empty_snapshot(): void
    {
        $project = Project::create(['name' => 'Empty project', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601702999',
            'name' => 'Empty project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('empty-session');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('empty-session');
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => ['tables' => []],
        ]);
        $wialon->shouldReceive('logoutSession')->once()->with('empty-session');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601702999',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'wialon_group_id' => '601702999',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
            'source_rows' => 0,
        ]);
    }

    public function test_command_prunes_legacy_and_operationally_excluded_rows(): void
    {
        $project = Project::create(['name' => 'Cleanup project', 'active' => true]);
        $repair = Project::create(['name' => 'Təmir', 'active' => true]);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601703001',
            'name' => 'Cleanup project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $repairGroup = ProjectWialonGroup::create([
            'project_id' => $repair->id,
            'wialon_group_id' => '601703002',
            'name' => 'Təmir - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        foreach ([
            [$project, $group, 'legacy-row', null, null],
            [$repair, $repairGroup, 'repair-row', '2026-07-28 00:00:00', '2026-07-28 23:59:59'],
        ] as [$rowProject, $rowGroup, $periodKey, $periodFrom, $periodTo]) {
            GeofenceViolationReportRow::create([
                'report_name' => GeofenceViolationReportRow::REPORT_NAME,
                'period_key' => $periodKey,
                'project_id' => $rowProject->id,
                'project_wialon_group_id' => $rowGroup->id,
                'wialon_unit_id' => $periodKey,
                'equipment_name' => $periodKey,
                'equipment_type' => 'Excavator',
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'project_name' => $rowProject->name,
                'exited_at' => '2026-07-28 10:00:00',
                'last_confirmed_at' => '2026-07-28 14:00:00',
                'outside_duration_seconds' => 14_400,
                'is_active' => false,
                'report_period_from' => $periodFrom,
                'report_period_to' => $periodTo,
                'report_generated_at' => '2026-07-28 14:05:00',
            ]);
        }

        GeofenceViolationSyncItem::create([
            'checkpoint_key' => sha1('repair-checkpoint'),
            'project_id' => $repair->id,
            'project_wialon_group_id' => $repairGroup->id,
            'wialon_group_id' => $repairGroup->wialon_group_id,
            'wialon_group_name' => $repairGroup->name,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'report_period_from' => '2026-07-28 00:00:00',
            'report_period_to' => '2026-07-28 23:59:59',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('cleanup-session');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('cleanup-session');
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => ['tables' => []],
        ]);
        $wialon->shouldReceive('logoutSession')->once()->with('cleanup-session');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601703001',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('geofence_violation_report_rows', ['period_key' => 'legacy-row']);
        $this->assertDatabaseMissing('geofence_violation_report_rows', ['period_key' => 'repair-row']);
        $this->assertDatabaseMissing('geofence_violation_sync_items', ['checkpoint_key' => sha1('repair-checkpoint')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unitRow(string $name, string $id, int $entry, int $exit, string $duration): array
    {
        return [
            'uid' => (int) $id,
            't1' => $entry,
            't2' => $exit,
            'c' => [
                $name,
                'Excavator',
                '',
                ['v' => $entry],
                ['v' => $exit],
                $duration,
            ],
        ];
    }
}
