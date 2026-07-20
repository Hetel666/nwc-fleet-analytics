<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardDailyAverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDailyAverageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_hours_uses_only_allowed_types_and_normalizes_bakhoe_loader(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $bakhoe = EquipmentType::query()->create(['name' => 'Bakhoe Loader']);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);

        $this->stat($this->equipment('Excavator 01', $excavator, $project), '2026-07-01', 8, 20);
        $this->stat($this->equipment('Bakhoe 01', $bakhoe, $project), '2026-07-01', 6, 30);
        $this->stat($this->equipment('Dump 01', $dumpTruck, $project), '2026-07-01', 100, 500);

        $rows = app(DashboardDailyAverageService::class)->dailyAverages([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours');

        $nwc = $rows->firstWhere('ownership', Equipment::OWNERSHIP_NWC);

        $this->assertSame(7.0, $nwc['average']);
        $this->assertSame(2, $nwc['valid_units_count']);
        $this->assertSame(0, $nwc['missing_units_count']);
    }

    public function test_mileage_uses_only_dump_truck(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $this->stat($this->equipment('Dump 01', $dumpTruck, $project), '2026-07-01', 8, 120);
        $this->stat($this->equipment('Dump 02', $dumpTruck, $project), '2026-07-01', 4, 80);
        $this->stat($this->equipment('Loader 01', $loader, $project), '2026-07-01', 10, 900);

        $rows = app(DashboardDailyAverageService::class)->dailyAverages([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'mileage');

        $nwc = $rows->firstWhere('ownership', Equipment::OWNERSHIP_NWC);

        $this->assertSame(100.0, $nwc['average']);
        $this->assertSame(2, $nwc['valid_units_count']);
    }

    public function test_date_range_returns_separate_daily_points_and_does_not_average_whole_period(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $roadRoller = EquipmentType::query()->create(['name' => 'Road Roller']);

        $excavatorUnit = $this->equipment('Excavator 01', $excavator, $project);
        $loaderUnit = $this->equipment('Loader 01', $loader, $project);
        $rollerUnit = $this->equipment('Roller 01', $roadRoller, $project);

        $this->stat($excavatorUnit, '2026-07-01', 8, 0);
        $this->stat($loaderUnit, '2026-07-01', 6, 0);
        $this->stat($excavatorUnit, '2026-07-02', 4, 0);
        $this->stat($loaderUnit, '2026-07-02', 8, 0);
        $this->stat($rollerUnit, '2026-07-02', 6, 0);
        $this->stat($excavatorUnit, '2026-07-03', 5, 0);

        $rows = app(DashboardDailyAverageService::class)->dailyAverages([
            'from' => '2026-07-01',
            'to' => '2026-07-03',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours');

        $this->assertSame([7.0, 6.0, 5.0], $rows->pluck('average')->all());
        $this->assertSame([2, 3, 1], $rows->pluck('valid_units_count')->all());
        $this->assertSame([1, 0, 2], $rows->pluck('missing_units_count')->all());
    }

    public function test_missing_day_returns_null_and_confirmed_zero_is_kept(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $unit = $this->equipment('Excavator 01', $excavator, $project);

        $this->stat($unit, '2026-07-01', 0, 0);

        $rows = app(DashboardDailyAverageService::class)->dailyAverages([
            'from' => '2026-07-01',
            'to' => '2026-07-02',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours');

        $this->assertSame(0.0, $rows[0]['average']);
        $this->assertNull($rows[1]['average']);
    }

    public function test_dashboard_data_returns_weighted_kpis_table_and_day_cards(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $unitA = $this->equipment('Excavator 01', $excavator, $project);
        $unitB = $this->equipment('Excavator 02', $excavator, $project);

        $this->stat($unitA, '2026-07-01', 10, 0);
        $this->stat($unitA, '2026-07-02', 0, 0);
        $this->stat($unitB, '2026-07-02', 0, 0);

        $data = app(DashboardDailyAverageService::class)->dashboardData([
            'from' => '2026-07-01',
            'to' => '2026-07-02',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours');

        $this->assertTrue($data['has_data']);
        $this->assertCount(2, $data['table_rows']);
        $this->assertCount(2, $data['day_cards']);
        $this->assertSame([10.0, 0.0], $data['chart']['series'][Equipment::OWNERSHIP_NWC]);
        $this->assertSame(3.3, $data['kpis'][Equipment::OWNERSHIP_NWC]['average']);
        $this->assertSame(3, $data['kpis'][Equipment::OWNERSHIP_NWC]['valid_units_count']);
        $this->assertSame(1, $data['kpis'][Equipment::OWNERSHIP_NWC]['missing_units_count']);
    }

    private function equipment(string $name, EquipmentType $type, Project $project, string $ownership = Equipment::OWNERSHIP_NWC): Equipment
    {
        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => (string) random_int(100000, 999999),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownership,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);
    }

    private function stat(Equipment $equipment, string $date, float $hours, float $distance): void
    {
        EquipmentDailyStat::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'distance_km' => $distance,
            'calculation_status' => 'success',
        ]);
    }
}
