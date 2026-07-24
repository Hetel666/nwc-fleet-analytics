<?php

namespace Tests\Feature;

use App\Models\DailyUnitAggregate;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardDataVersion;
use App\Services\WialonDashboardDatasetSyncService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WialonDashboardDatasetSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_writes_local_dataset_and_bumps_version_once_after_successful_commit(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
        ]);

        [$project] = $this->createSyncTarget();
        $this->app->instance(WialonService::class, $this->fakeWialon('07:30:00', '31.2 km'));

        $result = app(WialonDashboardDatasetSyncService::class)->syncDailyEngineHoursReport([
            'date_from' => '2026-07-02',
            'date_to' => '2026-07-02',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], true);

        $this->assertSame('synced', $result['status']);
        $this->assertSame(1, $result['equipment_count']);
        $this->assertSame(2, app(DashboardDataVersion::class)->current());
        $this->assertSame(1, EquipmentDailyStat::count());
        $this->assertSame(1, DailyUnitAggregate::count());
    }

    public function test_transaction_rollback_does_not_leave_partial_data_or_bump_version(): void
    {
        Cache::flush();
        config([
            'fleet.wialon.engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.engine_hours_report_template_id' => 9,
        ]);

        [$project] = $this->createSyncTarget();
        $this->app->instance(WialonService::class, $this->fakeWialon('08:00:00', '42 km'));

        DB::statement(
            "CREATE TRIGGER fail_daily_unit_aggregates_insert BEFORE INSERT ON daily_unit_aggregates BEGIN SELECT RAISE(ABORT, 'Simulated DB failure.'); END;"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated DB failure.');

        try {
            app(WialonDashboardDatasetSyncService::class)->syncDailyEngineHoursReport([
                'date_from' => '2026-07-02',
                'date_to' => '2026-07-02',
                'project_id' => $project->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
            ], true);
        } finally {
            $this->assertSame(1, app(DashboardDataVersion::class)->current());
            $this->assertSame(0, EquipmentDailyStat::count());
            $this->assertSame(0, DailyUnitAggregate::count());
            DB::statement('DROP TRIGGER IF EXISTS fail_daily_unit_aggregates_insert');
        }
    }

    private function createSyncTarget(): array
    {
        $project = Project::create(['name' => 'Fuzuli Agdam yol', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Fuzuli Agdam yol NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $equipment = Equipment::create([
            'name' => '77-TEST-001',
            'wialon_unit_id' => '710001',
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

    private function fakeWialon(string $hours, string $mileage): WialonService
    {
        return new class($hours, $mileage) extends WialonService
        {
            public function __construct(private string $hours, private string $mileage) {}

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
                        'rows' => [[
                            'c' => ['77-TEST-001', 'NWC', 'Dump Truck', $this->hours, $this->mileage],
                        ]],
                    ]],
                ];
            }
        };
    }
}
