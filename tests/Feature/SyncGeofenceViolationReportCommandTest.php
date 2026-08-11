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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geofence_violations.empty_snapshot_attempts', 1);
    }

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
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'dedicated-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('dedicated-session');
        $wialon->shouldReceive('executeReportTemplate')
            ->once()
            ->withArgs(function (...$arguments): bool {
                $this->assertSame(601701680, $arguments[0]);
                $this->assertTrue(
                    $this->isFullDetailTemplate($arguments[1]),
                    json_encode($arguments[1], JSON_THROW_ON_ERROR)
                );
                $this->assertSame('601701886', $arguments[2]);
                $this->assertIsInt($arguments[3]);
                $this->assertIsInt($arguments[4]);
                $this->assertSame([0, 'dedicated-session', false, 60], array_slice($arguments, 5));

                return true;
            })
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

    public function test_excluded_layihesiz_group_is_not_processed_by_geofence_violations_command(): void
    {
        $project = Project::create(['name' => 'Layihəsiz', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601705305',
            'name' => 'Layihəsiz - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldNotReceive('loginByToken');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601705305',
        ])->assertExitCode(2);
    }

    public function test_full_detail_rows_are_imported_as_independent_periods(): void
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

        Equipment::create([
            'name' => '10-AF-100',
            'wialon_unit_id' => '601700100',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        $firstEntry = CarbonImmutable::parse('2026-07-28 08:00:00', 'Asia/Baku')->timestamp;
        $firstExit = CarbonImmutable::parse('2026-07-28 12:00:00', 'Asia/Baku')->timestamp;
        $secondEntry = CarbonImmutable::parse('2026-07-28 17:00:00', 'Asia/Baku')->timestamp;
        $secondExit = CarbonImmutable::parse('2026-07-28 22:00:00', 'Asia/Baku')->timestamp;
        $rootRow = [
            'c' => ['Out of geofences', '', '', '', '', ''],
            'r' => [
                [
                    'uid' => 601700100,
                    'c' => ['10-AF-100', 'Excavator', '', '', '', '9:00:00'],
                    'r' => [
                        $this->unitRow('10-AF-100', '601700100', $firstEntry, $firstExit, '4:00:00'),
                        $this->unitRow('10-AF-100', '601700100', $secondEntry, $secondExit, '5:00:00'),
                    ],
                ],
            ],
        ];
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('atomic-session');
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'atomic-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('atomic-session');
        $wialon->shouldReceive('executeReportTemplate')->once()->andReturn([
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
        ])->assertSuccessful();

        $this->assertDatabaseCount('geofence_violation_report_rows', 2);
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'wialon_unit_id' => '601700100',
            'exited_at' => '2026-07-28 08:00:00',
            'outside_duration_seconds' => 14_400,
            'last_project_geofence' => null,
        ]);
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'wialon_unit_id' => '601700100',
            'exited_at' => '2026-07-28 17:00:00',
            'outside_duration_seconds' => 18_000,
        ]);
        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'project_wialon_group_id' => $group->id,
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
            'imported_rows' => 2,
            'rejected_rows' => 0,
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
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'empty-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('empty-session');
        $wialon->shouldReceive('executeReportTemplate')->once()->andReturn([
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

    public function test_false_empty_snapshot_is_retried_before_success(): void
    {
        config()->set('geofence_violations.empty_snapshot_attempts', 2);
        config()->set('geofence_violations.empty_snapshot_retry_delay_ms', 1);

        $project = Project::create(['name' => 'Confirmed empty project', 'active' => true]);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601703000',
            'name' => 'Confirmed empty project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        Equipment::create([
            'name' => '10-AF-300',
            'wialon_unit_id' => '601700300',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
        $entry = CarbonImmutable::parse('2026-07-28 08:00:00', 'Asia/Baku')->timestamp;
        $exit = CarbonImmutable::parse('2026-07-28 12:00:01', 'Asia/Baku')->timestamp;

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('empty-confirm-session');
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'empty-confirm-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->times(3)->with('empty-confirm-session');
        $wialon->shouldReceive('executeReportTemplate')
            ->twice()
            ->andReturn(
                ['reportResult' => ['tables' => []]],
                [
                    'reportResult' => [
                        'tables' => [[
                            'name' => 'unit_group_zones_visit',
                            'header' => ['Grouping', 'Type', 'geofence', 'Entry', 'Exit', 'Duration'],
                            'header_type' => ['', 'user_column', 'zone_name', 'time_begin', 'time_end', 'duration_in'],
                            'rows' => 1,
                            'level' => 3,
                        ]],
                    ],
                ]
            );
        $wialon->shouldReceive('selectReportResultRows')
            ->once()
            ->andReturn([[
                'c' => ['Out of geofences', '', '', '', '', ''],
                'r' => [[
                    'uid' => 601700300,
                    'c' => ['10-AF-300', 'Excavator', '', '', '', '4:00:01'],
                    'r' => [
                        $this->unitRow('10-AF-300', '601700300', $entry, $exit, '4:00:01'),
                    ],
                ]],
            ]]);
        $wialon->shouldReceive('logoutSession')->once()->with('empty-confirm-session');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601703000',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'wialon_group_id' => '601703000',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
            'source_rows' => 1,
            'imported_rows' => 1,
        ]);
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'wialon_unit_id' => '601700300',
            'outside_duration_seconds' => 14_401,
        ]);
    }

    public function test_temporary_wialon_1004_renews_the_dedicated_session_and_retries(): void
    {
        config()->set('geofence_violations.report_attempts', 2);
        config()->set('geofence_violations.report_retry_delay_ms', 1);

        $project = Project::create(['name' => 'Retry project', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601703099',
            'name' => 'Retry project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')
            ->twice()
            ->with(false)
            ->andReturn('expired-session', 'renewed-session');
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'expired-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('expired-session');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('renewed-session');
        $wialon->shouldReceive('executeReportTemplate')
            ->once()
            ->withArgs(fn (...$arguments): bool => $arguments[6] === 'expired-session')
            ->andThrow(new \RuntimeException('Wialon API error 1004 for report/exec_report.'));
        $wialon->shouldReceive('executeReportTemplate')
            ->once()
            ->withArgs(fn (...$arguments): bool => $arguments[6] === 'renewed-session')
            ->andReturn(['reportResult' => ['tables' => []]]);
        $wialon->shouldReceive('logoutSession')->once()->with('expired-session');
        $wialon->shouldReceive('logoutSession')->once()->with('renewed-session');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-28 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601703099',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'wialon_group_id' => '601703099',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
        ]);
    }

    public function test_timeout_falls_back_to_overlapping_chunks_and_merges_only_continuous_periods(): void
    {
        config()->set('geofence_violations.report_attempts', 1);
        config()->set('geofence_violations.fallback_chunk_hours', 24);
        config()->set('geofence_violations.fallback_overlap_seconds', 10_801);

        $project = Project::create(['name' => 'Chunked project', 'active' => true]);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601703100',
            'name' => 'Chunked project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        Equipment::create([
            'name' => '10-AF-500',
            'wialon_unit_id' => '601700500',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        $timestamp = fn (string $value): int => CarbonImmutable::parse($value, 'Asia/Baku')->timestamp;
        $table = [
            'name' => 'unit_group_zones_visit',
            'header' => ['Grouping', 'Type', 'geofence', 'Entry', 'Exit', 'Duration'],
            'header_type' => ['', 'user_column', 'zone_name', 'time_begin', 'time_end', 'duration_in'],
            'rows' => 1,
            'level' => 2,
        ];
        $firstChunk = [[
            'c' => ['Out of geofences', '', '', '', '', ''],
            'r' => [
                $this->unitRow(
                    '10-AF-500',
                    '601700500',
                    $timestamp('2026-07-27 10:00:00'),
                    $timestamp('2026-07-28 03:00:00'),
                    '17:00:00'
                ),
            ],
        ]];
        $secondChunk = [[
            'c' => ['Out of geofences', '', '', '', '', ''],
            'r' => [
                $this->unitRow(
                    '10-AF-500',
                    '601700500',
                    $timestamp('2026-07-27 20:59:59'),
                    $timestamp('2026-07-28 10:00:00'),
                    '13:00:01'
                ),
                $this->unitRow(
                    '10-AF-500',
                    '601700500',
                    $timestamp('2026-07-28 15:00:00'),
                    $timestamp('2026-07-28 19:00:00'),
                    '4:00:00'
                ),
            ],
        ]];

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('chunk-session');
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'chunk-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->times(4)->with('chunk-session');
        $wialon->shouldReceive('executeReportTemplate')
            ->once()
            ->andThrow(new \RuntimeException('cURL error 28: Operation timed out'));
        $wialon->shouldReceive('executeReportTemplate')
            ->twice()
            ->andReturn(
                ['reportResult' => ['tables' => [$table]]],
                ['reportResult' => ['tables' => [$table]]]
            );
        $wialon->shouldReceive('selectReportResultRows')
            ->twice()
            ->andReturn($firstChunk, $secondChunk);
        $wialon->shouldReceive('logoutSession')->once()->with('chunk-session');
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-geofence-violations-report', [
            '--from' => '2026-07-27 00:00:00',
            '--to' => '2026-07-28 23:59:59',
            '--group' => '601703100',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('geofence_violation_report_rows', 2);
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'wialon_unit_id' => '601700500',
            'exited_at' => '2026-07-27 10:00:00',
            'last_confirmed_at' => '2026-07-28 10:00:00',
            'outside_duration_seconds' => 86_400,
        ]);
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'wialon_unit_id' => '601700500',
            'exited_at' => '2026-07-28 15:00:00',
            'outside_duration_seconds' => 14_400,
        ]);
        $this->assertDatabaseHas('geofence_violation_sync_items', [
            'wialon_group_id' => '601703100',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
            'source_rows' => 3,
            'imported_rows' => 2,
        ]);
    }

    public function test_command_prunes_legacy_rows_and_excluded_project_rows(): void
    {
        $project = Project::create(['name' => 'Cleanup project', 'active' => true]);
        $excluded = Project::create(['name' => Project::DASHBOARD_UNASSIGNED_NAMES[0], 'active' => true]);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601703001',
            'name' => 'Cleanup project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $excludedGroup = ProjectWialonGroup::create([
            'project_id' => $excluded->id,
            'wialon_group_id' => '601703002',
            'name' => 'Excluded - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        foreach ([
            [$project, $group, 'legacy-row', null, null],
            [$excluded, $excludedGroup, 'excluded-row', '2026-07-28 00:00:00', '2026-07-28 23:59:59'],
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
            'checkpoint_key' => sha1('excluded-checkpoint'),
            'project_id' => $excluded->id,
            'project_wialon_group_id' => $excludedGroup->id,
            'wialon_group_id' => $excludedGroup->wialon_group_id,
            'wialon_group_name' => $excludedGroup->name,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'report_period_from' => '2026-07-28 00:00:00',
            'report_period_to' => '2026-07-28 23:59:59',
            'status' => GeofenceViolationSyncItem::STATUS_COMPLETED,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('cleanup-session');
        $wialon->shouldReceive('getReportTemplateData')
            ->once()
            ->with(601701680, 22, 'cleanup-session')
            ->andReturn($this->reportTemplate());
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('cleanup-session');
        $wialon->shouldReceive('executeReportTemplate')->once()->andReturn([
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
        $this->assertDatabaseMissing('geofence_violation_report_rows', ['period_key' => 'excluded-row']);
        $this->assertDatabaseMissing('geofence_violation_sync_items', ['checkpoint_key' => sha1('excluded-checkpoint')]);
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
                'Out of geofences',
                ['v' => $entry],
                ['v' => $exit],
                $duration,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportTemplate(): array
    {
        return [
            'id' => 22,
            'n' => GeofenceViolationReportRow::REPORT_NAME,
            'tbl' => [
                ['n' => 'unit_group_stats', 'f' => 0],
                [
                    'n' => 'unit_group_zones_visit',
                    'f' => 0x10,
                    'p' => json_encode([
                        'duration' => ['min' => 10_801, 'flags' => 1],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function isFullDetailTemplate(array $template): bool
    {
        $table = collect($template['tbl'] ?? [])
            ->firstWhere('n', 'unit_group_zones_visit');

        return is_array($table)
            && ($table['f'] ?? null) === 0x800
            && str_contains((string) ($table['p'] ?? ''), '"min":10801');
    }
}
