<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardDailyAverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardDailyAverageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_hours_are_calculated_independently_by_vehicle_type(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $excavators = collect(range(1, 5))
            ->map(fn (int $index): Equipment => $this->equipment('Excavator '.$index, $excavator, $project));
        $loaders = collect(range(1, 2))
            ->map(fn (int $index): Equipment => $this->equipment('Loader '.$index, $loader, $project));

        $excavators->each(function (Equipment $unit): void {
            $this->stat($unit, '2026-07-01', 67, 0);
        });
        $loaders->each(function (Equipment $unit): void {
            $this->stat($unit, '2026-07-01', 70, 0);
        });

        $rows = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-01',
            'to' => '2026-07-07',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours')->keyBy('type_code');

        $this->assertSame(9.57, $rows['excavator']['average_per_unit_per_day']);
        $this->assertSame(5, $rows['excavator']['units_count']);
        $this->assertSame(7, $rows['excavator']['days_count']);
        $this->assertSame(335.0, $rows['excavator']['total_value']);

        $this->assertSame(10.0, $rows['loader']['average_per_unit_per_day']);
        $this->assertSame(2, $rows['loader']['units_count']);
        $this->assertSame(140.0, $rows['loader']['total_value']);
    }

    public function test_ownership_project_and_inclusive_one_day_filters_are_applied_before_aggregation(): void
    {
        $projectA = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $projectB = Project::query()->create(['name' => 'Project B', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);

        $nwc = $this->equipment('NWC Excavator', $excavator, $projectA, Equipment::OWNERSHIP_NWC);
        $icare = $this->equipment('Icare Excavator', $excavator, $projectA, Equipment::OWNERSHIP_ICARE);
        $otherProject = $this->equipment('Other Project Excavator', $excavator, $projectB, Equipment::OWNERSHIP_NWC);

        $this->stat($nwc, '2026-07-01', 8, 0);
        $this->stat($icare, '2026-07-01', 5, 0);
        $this->stat($otherProject, '2026-07-01', 20, 0);

        $rows = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'project_id' => $projectA->id,
        ], 'engine_hours');

        $nwcRow = $rows->first(fn (array $row): bool => $row['type_code'] === 'excavator' && $row['ownership'] === Equipment::OWNERSHIP_NWC);
        $icareRow = $rows->first(fn (array $row): bool => $row['type_code'] === 'excavator' && $row['ownership'] === Equipment::OWNERSHIP_ICARE);

        $this->assertSame(1, $nwcRow['days_count']);
        $this->assertSame(8.0, $nwcRow['average_per_unit_per_day']);
        $this->assertSame(5.0, $icareRow['average_per_unit_per_day']);
    }

    public function test_bakhoe_loader_is_normalized_and_missing_units_are_counted_separately(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $bakhoe = EquipmentType::query()->create(['name' => 'Bakhoe Loader']);

        $withData = $this->equipment('Backhoe 01', $bakhoe, $project);
        $withoutData = $this->equipment('Backhoe 02', $bakhoe, $project);

        $this->stat($withData, '2026-07-01', 0, 0);

        $row = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-01',
            'to' => '2026-07-02',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'vehicle_types' => ['backhoe-loader'],
        ], 'engine_hours')->firstWhere('type_code', 'backhoe_loader');

        $this->assertSame('Backhoe Loader', $row['vehicle_type']);
        $this->assertSame(2, $row['units_count']);
        $this->assertSame(1, $row['units_without_data']);
        $this->assertSame(0.0, $row['average_per_unit_per_day']);
    }

    public function test_mileage_still_uses_dump_truck_and_excludes_negative_values(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $dumpA = $this->equipment('Dump 01', $dumpTruck, $project);
        $dumpB = $this->equipment('Dump 02', $dumpTruck, $project);
        $loaderUnit = $this->equipment('Loader 01', $loader, $project);

        $this->stat($dumpA, '2026-07-01', 8, 120);
        $this->stat($dumpB, '2026-07-01', 4, -80);
        $this->stat($loaderUnit, '2026-07-01', 10, 900);

        $row = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'mileage')->firstWhere('type_code', 'dump_truck');

        $this->assertSame(60.0, $row['average_per_unit_per_day']);
        $this->assertSame(2, $row['units_count']);
        $this->assertSame(1, $row['units_without_data']);
    }

    public function test_duplicate_unit_day_is_not_summed_twice(): void
    {
        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            $table->dropUnique(['stat_date', 'equipment_id']);
        });

        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $unit = $this->equipment('Excavator 01', $excavator, $project);

        $this->stat($unit, '2026-07-01', 6, 0);
        $this->stat($unit, '2026-07-01', 9, 0);

        $row = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours')->firstWhere('type_code', 'excavator');

        $this->assertSame(9.0, $row['total_value']);
        $this->assertSame(9.0, $row['average_per_unit_per_day']);
    }

    public function test_dashboard_data_and_exports_use_type_summary_rows(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $unit = $this->equipment('Excavator 01', $excavator, $project);

        $this->stat($unit, '2026-07-01', 8, 12);

        $service = app(DashboardDailyAverageService::class);
        $data = $service->dashboardData([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 'engine_hours');

        $this->assertTrue($data['has_data']);
        $this->assertSame('Excavator', collect($data['type_rows'])->firstWhere('type_code', 'excavator')['vehicle_type']);
        $summaryRow = collect($service->summaryRows([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'vehicle_types' => ['excavator'],
        ], 'engine_hours'))->first(fn (array $row): bool => $row[0] === 'Excavator');

        $this->assertSame([
            'Excavator',
            'NWC',
            '8.00 saat',
            1,
            1,
            '8.00 saat',
            0,
        ], $summaryRow);
    }

    public function test_published_daily_stats_are_treated_as_available_dashboard_data(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $unit = $this->equipment('Excavator 01', $excavator, $project);

        $this->stat($unit, '2026-07-26', 8, 12, 'published');

        $row = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-26',
            'to' => '2026-07-26',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'vehicle_types' => ['excavator'],
        ], 'engine_hours')->firstWhere('type_code', 'excavator');

        $this->assertSame(8.0, $row['total_value']);
        $this->assertSame(8.0, $row['average_per_unit_per_day']);
        $this->assertSame(0, $row['units_without_data']);
    }

    public function test_ok_daily_stats_remain_available_for_shift_sync_data(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $unit = $this->equipment('Loader 01', $loader, $project);

        $this->stat($unit, '2026-07-25', 6.5, 0, 'ok');

        $row = app(DashboardDailyAverageService::class)->typeSummary([
            'from' => '2026-07-25',
            'to' => '2026-07-25',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'vehicle_types' => ['loader'],
        ], 'engine_hours')->firstWhere('type_code', 'loader');

        $this->assertSame(6.5, $row['total_value']);
        $this->assertSame(6.5, $row['average_per_unit_per_day']);
        $this->assertSame(0, $row['units_without_data']);
    }

    public function test_average_journal_uses_database_pagination_and_keeps_missing_rows(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);

        collect(range(1, 15))->each(function (int $index) use ($excavator, $project): void {
            $unit = $this->equipment(sprintf('Excavator %02d', $index), $excavator, $project);

            if ($index <= 5) {
                $this->stat($unit, '2026-07-01', $index, 0);
            }
        });

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $page = app(DashboardDailyAverageService::class)->paginateJournal([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'vehicle_types' => ['excavator'],
            'page' => 2,
            'per_page' => 10,
            'sort' => 'name',
        ], 'engine_hours');

        $this->assertSame(15, $page->total());
        $this->assertSame(5, $page->count());
        $this->assertSame('Excavator 11', $page->items()[0]['name']);
        $this->assertFalse($page->items()[0]['data_available']);
        $this->assertTrue(collect($queries)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'limit')));
    }

    public function test_average_journal_missing_filter_is_applied_before_pagination(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        collect(range(1, 12))->each(function (int $index) use ($loader, $project): void {
            $unit = $this->equipment(sprintf('Loader %02d', $index), $loader, $project);

            if ($index <= 3) {
                $this->stat($unit, '2026-07-01', 4, 0);
            }
        });

        $page = app(DashboardDailyAverageService::class)->paginateJournal([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'vehicle_types' => ['loader'],
            'data_status' => 'missing',
            'page' => 1,
            'per_page' => 10,
        ], 'engine_hours');

        $this->assertSame(9, $page->total());
        $this->assertSame(9, $page->count());
        $this->assertTrue(collect($page->items())->every(fn (array $row): bool => $row['data_available'] === false));
    }

    private function equipment(string $name, EquipmentType $type, Project $project, string $ownership = Equipment::OWNERSHIP_NWC): Equipment
    {
        return Equipment::query()->create([
            'name' => $name,
            'registration_number' => $name.' RN',
            'wialon_unit_id' => (string) random_int(100000, 999999),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownership,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);
    }

    private function stat(Equipment $equipment, string $date, float $hours, float $distance, string $status = 'success'): void
    {
        EquipmentDailyStat::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'distance_km' => $distance,
            'calculation_status' => $status,
        ]);
    }
}
