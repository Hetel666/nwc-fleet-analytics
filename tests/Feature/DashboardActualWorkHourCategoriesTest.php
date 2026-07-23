<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardActualWorkHourCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_work_hour_categories_use_average_daily_values_and_count_each_unit_once(): void
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
                'less_than_1' => 2,
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
    }

    public function test_actual_work_hour_categories_respect_equipment_type_and_ownership_filters(): void
    {
        $project = Project::create(['name' => 'Fuzuli Agdam yol', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $truck = EquipmentType::create(['name' => 'Truck']);

        $selected = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'Selected');
        $otherType = $this->equipment($project, $truck, Equipment::OWNERSHIP_NWC, 'Other type');
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

    public function test_single_day_actual_work_hour_categories_use_prepared_local_stats(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC no report row');
        $nwcMiddle = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC middle');
        $nwcRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC regular');
        $nwcOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $icareLess = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE less');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701936',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->stats($project, $nwcMiddle, [5.25]);
        $this->stats($project, $nwcRegular, [8.5]);
        $this->stats($project, $nwcOvertime, [11]);
        $this->stats($project, $icareLess, [0.5]);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
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
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
        ], $result);
    }

    public function test_date_range_actual_work_hour_categories_use_prepared_local_stats(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC no report row');
        $nwcMiddle = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC middle');
        $nwcRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC regular');
        $nwcOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $icareLess = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE less');
        $icareRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE regular');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701936',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->stats($project, $nwcMiddle, [5, 5]);
        $this->stats($project, $nwcRegular, [10, 10]);
        $this->stats($project, $nwcOvertime, [11, 11]);
        $this->stats($project, $icareLess, [0.75, 0.75]);
        $this->stats($project, $icareRegular, [7, 7]);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

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
                'overtime' => 0,
            ],
        ], $result);

    }

    public function test_project_work_hour_cards_use_prepared_local_stats_and_track_missing_data(): void
    {
        Cache::flush();

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

        $nwcZero = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC zero');
        $nwcLess = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC less');
        $nwcFromOne = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC from one');
        $nwcSeven = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC seven');
        $nwcTen = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC ten');
        $nwcOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC missing');
        $icareDay = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE day');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE invalid');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701936',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->stats($project, $nwcZero, [0]);
        $this->stats($project, $nwcLess, [0.99]);
        $this->stats($project, $nwcFromOne, [1]);
        $this->stats($project, $nwcSeven, [7]);
        $this->stats($project, $nwcTen, [10]);
        $this->stats($project, $nwcOvertime, [10.01]);
        $this->stats($project, $icareDay, [26.5]);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

        $result = app(DashboardService::class)->getProjectActualWorkHourCategoriesByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame([
            'less_than_1' => 2,
            'from_1_to_7' => 1,
            'from_7_to_10' => 2,
            'overtime' => 1,
            'total' => 6,
            'missing_data' => 1,
        ], array_intersect_key($result[Equipment::OWNERSHIP_NWC][0], array_flip([
            'less_than_1',
            'from_1_to_7',
            'from_7_to_10',
            'overtime',
            'total',
            'missing_data',
        ])));

        $this->assertSame([
            'less_than_1' => 0,
            'from_1_to_7' => 0,
            'from_7_to_10' => 0,
            'overtime' => 1,
            'total' => 1,
            'missing_data' => 1,
        ], array_intersect_key($result[Equipment::OWNERSHIP_ICARE][0], array_flip([
            'less_than_1',
            'from_1_to_7',
            'from_7_to_10',
            'overtime',
            'total',
            'missing_data',
        ])));
    }

    public function test_average_metrics_by_ownership_use_prepared_engine_hours_and_mileage_stats(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT1', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Truck']);

        $nwcFirst = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC first');
        $nwcSecond = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC second');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC without row');
        $icareFirst = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE first');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701930',
            'name' => 'LOT1 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701933',
            'name' => 'LOT1 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->metricStat($project, $nwcFirst, 10.0, 120.5);
        $this->metricStat($project, $nwcSecond, 8.0, 79.5);
        $this->metricStat($project, $icareFirst, 7.4, 55.0);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

        $result = app(DashboardService::class)->getAverageMetricsByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-11',
        ]);

        $this->assertSame(3, $result[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(6.0, $result[Equipment::OWNERSHIP_NWC]['avg_hours']);
        $this->assertSame(66.7, $result[Equipment::OWNERSHIP_NWC]['avg_mileage']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_ICARE]['count']);
        $this->assertSame(7.4, $result[Equipment::OWNERSHIP_ICARE]['avg_hours']);
        $this->assertSame(55.0, $result[Equipment::OWNERSHIP_ICARE]['avg_mileage']);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownershipType,
        ]);
    }

    /**
     * @param  list<float|int>  $hours
     */
    private function stats(Project $project, Equipment $equipment, array $hours): void
    {
        foreach ($hours as $index => $workedHours) {
            EquipmentDailyStat::create([
                'stat_date' => '2026-07-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'equipment_id' => $equipment->id,
                'project_id' => $project->id,
                'ownership_type' => $equipment->ownership_type,
                'worked_hours' => $workedHours,
            ]);
        }
    }

    private function metricStat(Project $project, Equipment $equipment, float $hours, float $mileage): void
    {
        EquipmentDailyStat::create([
            'stat_date' => '2026-07-01',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'distance_km' => $mileage,
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'success',
        ]);
    }
}
