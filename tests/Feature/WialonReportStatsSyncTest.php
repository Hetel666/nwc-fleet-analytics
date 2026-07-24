<?php

namespace Tests\Feature;

use App\Models\DailyUnitAggregate;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardDataVersion;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class WialonReportStatsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_group_report_is_stored_and_reused_by_dashboard(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
            'fleet.wialon.daily_engine_hours_report_timeout' => 30,
        ]);

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $equipment = Equipment::create([
            'name' => '90-TEST-001',
            'wialon_unit_id' => '700001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'planned_daily_hours' => 10,
            'active' => true,
        ]);

        $wialon = new class extends WialonService
        {
            public int $calls = 0;

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
                $this->calls++;

                return [
                    'tables' => [[
                        'table' => [
                            'label' => 'Engine hours',
                            'header' => ['Grouping', 'Vendor', 'Type', 'Engine hours', 'Mileage'],
                            'header_type' => ['', 'user_column', 'user_column', 'duration', 'mileage'],
                        ],
                        'rows' => [[
                            'c' => ['90-TEST-001', 'NWC', 'Dump Truck', '08:00:00', '42.5 km'],
                        ]],
                    ]],
                ];
            }
        };

        $this->app->instance(WialonService::class, $wialon);
        $this->assertSame(1, app(DashboardDataVersion::class)->current());

        $this->artisan('fleet:sync-report-stats', [
            '--date' => '2026-06-01',
            '--project' => $project->id,
            '--ownership' => Equipment::OWNERSHIP_NWC,
        ])->assertExitCode(0);

        $this->assertSame(2, app(DashboardDataVersion::class)->current());

        EquipmentDailyStat::query()->update(['stat_date' => '2026-06-01']);
        DailyUnitAggregate::query()->update(['date' => '2026-06-01']);

        $dailyStat = EquipmentDailyStat::where('equipment_id', $equipment->id)->firstOrFail();

        $this->assertSame('2026-06-01', $dailyStat->stat_date->toDateString());
        $this->assertSame(8.00, (float) $dailyStat->worked_hours);
        $this->assertSame(42.50, (float) $dailyStat->distance_km);
        $this->assertSame('wialon_engine_hours_report', $dailyStat->calculation_source);

        $dailyAggregate = DailyUnitAggregate::where('unit_id', '700001')->firstOrFail();

        $this->assertSame('2026-06-01', $dailyAggregate->date->toDateString());
        $this->assertSame(8.00, (float) $dailyAggregate->engine_hours);
        $this->assertSame(42.50, (float) $dailyAggregate->mileage);

        $this->assertDatabaseHas('equipment_daily_stats', [
            'equipment_id' => $equipment->id,
            'worked_hours' => 8.00,
            'distance_km' => 42.50,
            'calculation_source' => 'wialon_engine_hours_report',
        ]);
        $this->assertDatabaseHas('daily_unit_aggregates', [
            'unit_id' => '700001',
            'engine_hours' => 8.00,
            'mileage' => 42.50,
        ]);

        $result = app(DashboardService::class)->getProjectActualWorkHourCategoriesByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-01',
        ]);

        $this->assertSame(1, $wialon->calls);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC][0]['from_7_to_10']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC][0]['total']);
    }

    public function test_daily_group_report_sync_is_idempotent_and_updates_existing_rows_when_forced(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
        ]);

        [$project, $equipment] = $this->createSyncTarget();

        $wialon = new class extends WialonService
        {
            public array $hours = ['08:00:00', '09:30:00'];

            public int $calls = 0;

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
                $this->calls++;
                $hours = $this->hours[min($this->calls - 1, count($this->hours) - 1)];

                return $this->report($hours, '55.0 km');
            }

            private function report(string $hours, string $mileage): array
            {
                return [
                    'tables' => [[
                        'table' => [
                            'label' => 'Engine hours',
                            'header' => ['Grouping', 'Vendor', 'Type', 'Engine hours', 'Mileage'],
                            'header_type' => ['', 'user_column', 'user_column', 'duration', 'mileage'],
                        ],
                        'rows' => [[
                            'c' => ['90-TEST-001', 'NWC', 'Dump Truck', $hours, $mileage],
                        ]],
                    ]],
                ];
            }
        };

        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('fleet:sync-report-stats', [
            '--date' => '2026-06-01',
            '--project' => $project->id,
            '--ownership' => Equipment::OWNERSHIP_NWC,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('fleet:sync-report-stats', [
            '--date' => '2026-06-01',
            '--project' => $project->id,
            '--ownership' => Equipment::OWNERSHIP_NWC,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, EquipmentDailyStat::count());
        $this->assertSame(1, DailyUnitAggregate::count());
        $this->assertSame(9.50, (float) EquipmentDailyStat::firstOrFail()->worked_hours);
        $this->assertSame(9.50, (float) DailyUnitAggregate::where('equipment_id', $equipment->id)->firstOrFail()->engine_hours);
        $this->assertSame(3, app(DashboardDataVersion::class)->current());
    }

    public function test_failed_daily_group_report_sync_does_not_bump_dashboard_data_version(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
        ]);

        [$project] = $this->createSyncTarget('90-TEST-FAIL', '700002');

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
                throw new RuntimeException('Wialon unavailable.');
            }
        });

        $this->assertSame(1, app(DashboardDataVersion::class)->current());

        $this->artisan('fleet:sync-report-stats', [
            '--date' => '2026-06-01',
            '--project' => $project->id,
            '--ownership' => Equipment::OWNERSHIP_NWC,
        ])->assertExitCode(1);

        $this->assertSame(1, app(DashboardDataVersion::class)->current());
        $this->assertDatabaseCount('equipment_daily_stats', 0);
        $this->assertDatabaseCount('daily_unit_aggregates', 0);
    }

    public function test_empty_daily_group_report_does_not_bump_version_when_nothing_changes(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
        ]);

        [$project] = $this->createSyncTarget();

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
                return [
                    'tables' => [[
                        'table' => [
                            'label' => 'Engine hours',
                            'header' => ['Grouping', 'Vendor', 'Type', 'Engine hours', 'Mileage'],
                            'header_type' => ['', 'user_column', 'user_column', 'duration', 'mileage'],
                        ],
                        'rows' => [],
                    ]],
                ];
            }
        });

        $this->artisan('fleet:sync-report-stats', [
            '--date' => '2026-06-01',
            '--project' => $project->id,
            '--ownership' => Equipment::OWNERSHIP_NWC,
        ])->assertExitCode(0);

        $this->assertSame(1, app(DashboardDataVersion::class)->current());
        $this->assertDatabaseCount('equipment_daily_stats', 0);
    }

    public function test_invalid_sync_command_options_return_invalid_exit_code(): void
    {
        $this->artisan('fleet:sync-report-stats', [
            '--ownership' => 'BAD',
        ])->assertExitCode(2);

        $this->artisan('fleet:sync-report-stats', [
            '--project' => 1,
            '--root-groups' => true,
        ])->assertExitCode(2);
    }

    /**
     * @return array{Project, Equipment}
     */
    private function createSyncTarget(string $name = '90-TEST-001', string $wialonUnitId = '700001'): array
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $equipment = Equipment::create([
            'name' => $name,
            'wialon_unit_id' => $wialonUnitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'planned_daily_hours' => 10,
            'active' => true,
        ]);

        return [$project, $equipment];
    }
}
