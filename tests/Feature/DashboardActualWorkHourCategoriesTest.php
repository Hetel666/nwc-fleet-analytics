<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DashboardActualWorkHourCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_work_hour_categories_use_local_average_daily_values_and_count_each_unit_once(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $nwcLess = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC less');
        $nwcWithoutStats = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC no stats');
        $nwcMiddle = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC middle');
        $nwcRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC regular');
        $nwcOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $icareLess = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE less');
        $icareRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE regular');
        $icareOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE overtime');

        $this->stats($project, $nwcLess, [0.5, 0.5]);
        $this->stats($project, $nwcMiddle, [4, 6]);
        $this->stats($project, $nwcRegular, [7, 10]);
        $this->stats($project, $nwcOvertime, [11, 13]);
        $this->stats($project, $icareLess, [0, 0.5]);
        $this->stats($project, $icareRegular, [7, 7]);
        $this->stats($project, $icareOvertime, [12, 12]);

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ]);

        $this->assertSame([
            Equipment::OWNERSHIP_NWC => [
                'less_than_1' => 1,
                'from_1_to_7' => 1,
                'from_7_to_10' => 1,
                'overtime' => 1,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 1,
                'from_1_to_7' => 0,
                'from_7_to_10' => 1,
                'overtime' => 1,
            ],
        ], $result);

        $this->assertDatabaseCount('equipment_daily_stats', 14);
        $this->assertNotNull($nwcWithoutStats->id);
    }

    public function test_actual_work_hour_categories_respect_equipment_type_and_ownership_filters(): void
    {
        $project = Project::create(['name' => 'Fuzuli Agdam yol', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $loader = EquipmentType::create(['name' => 'Loader']);

        $selected = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'Selected');
        $otherType = $this->equipment($project, $loader, Equipment::OWNERSHIP_NWC, 'Other type');
        $otherOwnership = $this->equipment($project, $excavator, Equipment::OWNERSHIP_ICARE, 'Other ownership');

        $this->stats($project, $selected, [5]);
        $this->stats($project, $otherType, [12]);
        $this->stats($project, $otherOwnership, [5]);

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'equipment_type_id' => $excavator->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame([
            Equipment::OWNERSHIP_NWC => [
                'less_than_1' => 0,
                'from_1_to_7' => 1,
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 0,
                'from_1_to_7' => 0,
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
        ], $result);
    }

    public function test_dashboard_read_path_does_not_call_wialon_when_live_reports_are_enabled(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);

        $project = Project::create(['name' => 'Yuxari Sirvan LOT1', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        $equipment = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'Local unit');
        $this->stats($project, $equipment, [8]);

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
                throw new RuntimeException('Dashboard must not call Wialon.');
            }
        });

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC]['from_7_to_10']);
    }

    public function test_average_metrics_by_ownership_use_local_engine_hours_and_mileage(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT1', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $dumpTruck = EquipmentType::create(['name' => 'Dump Truck']);
        $pickup = EquipmentType::create(['name' => 'Pickup']);

        $nwcExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC excavator');
        $nwcZeroExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC zero excavator');
        $this->equipment($project, $pickup, Equipment::OWNERSHIP_NWC, 'NWC pickup');
        $nwcDump = $this->equipment($project, $dumpTruck, Equipment::OWNERSHIP_NWC, 'NWC dump');
        $icareExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_ICARE, 'ICARE excavator');
        $icareDump = $this->equipment($project, $dumpTruck, Equipment::OWNERSHIP_ICARE, 'ICARE dump');

        $this->stat($project, $nwcExcavator, '2026-07-01', 10, 12);
        $this->stat($project, $nwcZeroExcavator, '2026-07-01', 0, 5);
        $this->stat($project, $nwcDump, '2026-07-01', 99, 120.5);
        $this->stat($project, $icareExcavator, '2026-07-01', 7.4, 1);
        $this->stat($project, $icareDump, '2026-07-01', 8, 55);

        $result = app(DashboardService::class)->getAverageMetricsByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-11',
        ]);

        $this->assertSame('Local stats', $result[Equipment::OWNERSHIP_NWC]['source']);
        $this->assertSame(4, $result[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(2, $result[Equipment::OWNERSHIP_NWC]['engine_hours_equipment_count']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC]['mileage_equipment_count']);
        $this->assertSame(5.0, $result[Equipment::OWNERSHIP_NWC]['avg_hours']);
        $this->assertSame(120.5, $result[Equipment::OWNERSHIP_NWC]['avg_mileage']);
        $this->assertSame(2, $result[Equipment::OWNERSHIP_ICARE]['count']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_ICARE]['engine_hours_equipment_count']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_ICARE]['mileage_equipment_count']);
        $this->assertSame(7.4, $result[Equipment::OWNERSHIP_ICARE]['avg_hours']);
        $this->assertSame(55.0, $result[Equipment::OWNERSHIP_ICARE]['avg_mileage']);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        $group = ProjectWialonGroup::query()->firstOrCreate(
            [
                'project_id' => $project->id,
                'ownership_type' => $ownershipType,
            ],
            [
                'wialon_group_id' => 'group-'.$project->id.'-'.$ownershipType,
                'name' => $project->name.' '.$ownershipType,
            ]
        );

        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => $ownershipType,
            'active' => true,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
        ]);
    }

    /**
     * @param  list<float|int>  $hours
     */
    private function stats(Project $project, Equipment $equipment, array $hours): void
    {
        foreach ($hours as $index => $workedHours) {
            $date = '2026-07-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $this->stat($project, $equipment, $date, $workedHours);
        }
    }

    private function stat(Project $project, Equipment $equipment, string $date, float|int $workedHours, float|int $distanceKm = 0): void
    {
        DB::table('equipment_daily_stats')->insert([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $workedHours,
            'distance_km' => $distanceKm,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
