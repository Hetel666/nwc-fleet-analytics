<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardDataVersion;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $this->assertDatabaseHas('equipment_daily_stats', [
            'stat_date' => '2026-06-01 00:00:00',
            'equipment_id' => $equipment->id,
            'worked_hours' => 8.00,
            'distance_km' => 42.50,
            'calculation_source' => 'wialon_engine_hours_report',
        ]);
        $this->assertDatabaseHas('daily_unit_aggregates', [
            'date' => '2026-06-01 00:00:00',
            'unit_id' => '700001',
            'engine_hours' => 8.00,
            'mileage' => 42.50,
        ]);

        DB::table('equipment_daily_stats')->update(['stat_date' => '2026-06-01']);

        $result = app(DashboardService::class)->getProjectActualWorkHourCategoriesByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-01',
        ]);

        $this->assertSame(1, $wialon->calls);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC][0]['from_7_to_10']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC][0]['total']);
    }

    public function test_failed_daily_group_report_sync_does_not_bump_dashboard_data_version(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
        ]);

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        Equipment::create([
            'name' => '90-TEST-FAIL',
            'wialon_unit_id' => '700002',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'planned_daily_hours' => 10,
            'active' => true,
        ]);

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
    }
}
