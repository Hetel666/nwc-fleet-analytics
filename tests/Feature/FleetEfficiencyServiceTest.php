<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\FleetEfficiencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetEfficiencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_allowed_types_by_unit_day_and_excludes_other_types(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $pickup = EquipmentType::query()->create(['name' => 'Pickup']);

        $allowed = $this->equipment('Excavator 01', $excavator, $project);
        $excluded = $this->equipment('Pickup 01', $pickup, $project);

        $this->stat($allowed, '2026-07-01', 5);
        $this->stat($allowed, '2026-07-02', 0.5);
        $this->stat($excluded, '2026-07-01', 8);

        $rows = app(FleetEfficiencyService::class)->projectRowsByOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-02',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $summary = $rows[Equipment::OWNERSHIP_NWC][0];

        $this->assertSame(1, $summary['less_than_1_hour']);
        $this->assertSame(1, $summary['less_than_7_hours']);
        $this->assertSame(0, $summary['between_7_and_10_hours']);
        $this->assertSame(2, $summary['total']);
    }

    public function test_it_counts_range_as_daily_rows_and_tracks_no_data_per_day(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Road Roller']);
        $equipment = $this->equipment('Roller 01', $type, $project);

        $this->stat($equipment, '2026-07-01', 3);
        $this->stat($equipment, '2026-07-03', 6);

        $summary = app(FleetEfficiencyService::class)->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-03',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(2, $summary['less_than_7_hours']);
        $this->assertSame(0, $summary['between_7_and_10_hours']);
        $this->assertSame(0, $summary['less_than_1_hour']);
        $this->assertSame(1, $summary['no_data']);
        $this->assertSame(1, $summary['missing_data']);
        $this->assertSame(3, $summary['total']);
    }

    public function test_overtime_is_independent_from_daytime_status_and_does_not_inflate_total(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        $equipment = $this->equipment('Loader 01', $type, $project);

        $this->stat($equipment, '2026-07-01', 6, 1.2);

        $summary = app(FleetEfficiencyService::class)->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $summary['less_than_7_hours']);
        $this->assertSame(1, $summary['overtime']);
        $this->assertSame(1, $summary['total']);
    }

    public function test_daytime_status_ignores_overtime_total_hours(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        $tenWithOvertime = $this->equipment('Loader 10 with overtime', $type, $project);
        $betweenWithoutOvertime = $this->equipment('Loader daytime only', $type, $project);
        $overTenDaytime = $this->equipment('Loader daytime over ten', $type, $project);

        $this->stat($tenWithOvertime, '2026-07-01', 10, 2);
        $this->stat($betweenWithoutOvertime, '2026-07-01', 9, 0);
        $this->stat($overTenDaytime, '2026-07-01', 10.5, 0);

        $service = app(FleetEfficiencyService::class);
        $summary = $service->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(2, $summary['between_7_and_10_hours']);
        $this->assertSame(2, $summary['over_10_hours']);
        $this->assertSame(1, $summary['overtime']);
        $this->assertSame(3, $summary['total']);

        $tenPlusRows = $service->paginate([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => FleetEfficiencyService::DAY_STATUS_OVER_10,
            'per_page' => 20,
        ]);

        $this->assertSame(2, $tenPlusRows->total());
        $this->assertEqualsCanonicalizing(
            ['Loader 10 with overtime', 'Loader daytime over ten'],
            collect($tenPlusRows->items())->pluck('name')->all()
        );
    }

    public function test_overtime_requires_positive_stored_hours(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        $nullOvertime = $this->equipment('Null overtime', $type, $project);
        $zeroOvertime = $this->equipment('Zero overtime', $type, $project);
        $positiveOvertime = $this->equipment('Positive overtime', $type, $project);

        $this->stat($nullOvertime, '2026-07-01', 6, null);
        $this->stat($zeroOvertime, '2026-07-01', 6, 0);
        $this->stat($positiveOvertime, '2026-07-01', 6, 0.01);

        $service = app(FleetEfficiencyService::class);

        $summary = $service->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $summary['overtime']);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['overtime_unknown']);

        $rows = $service->exportRows([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => 'overtime',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Positive overtime', $rows[0][2]);
    }

    public function test_daytime_category_does_not_require_overtime_data(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $equipment = $this->equipment('Daytime only excavator', $type, $project);

        $this->stat($equipment, '2026-07-01', 3.5, null);

        $service = app(FleetEfficiencyService::class);
        $summary = $service->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(0, $summary['less_than_1_hour']);
        $this->assertSame(1, $summary['less_than_7_hours']);
        $this->assertSame(0, $summary['missing_data']);
        $this->assertSame(1, $summary['overtime_unknown']);

        $lessThanOne = $service->paginate([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => FleetEfficiencyService::DAY_STATUS_LESS_THAN_1,
            'per_page' => 20,
        ]);
        $lessThanSeven = $service->paginate([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => FleetEfficiencyService::DAY_STATUS_LESS_THAN_7,
            'per_page' => 20,
        ]);

        $this->assertSame(0, $lessThanOne->total());
        $this->assertSame(1, $lessThanSeven->total());
        $this->assertSame('Daytime only excavator', $lessThanSeven->items()[0]['name']);
        $this->assertSame(3.5, $lessThanSeven->items()[0]['daytime_hours']);
        $this->assertTrue($lessThanSeven->items()[0]['data_available']);
        $this->assertNull($lessThanSeven->items()[0]['has_overtime']);
    }

    public function test_missing_data_is_separate_from_less_than_one_hour_and_can_be_filtered(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $withData = $this->equipment('Excavator 01', $type, $project);
        $missingData = $this->equipment('Excavator 02', $type, $project);

        $this->stat($withData, '2026-07-01', 0.5);

        $service = app(FleetEfficiencyService::class);

        $summary = $service->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $summary['less_than_1_hour']);
        $this->assertSame(1, $summary['no_data']);
        $this->assertSame(1, $summary['missing_data']);
        $this->assertSame(2, $summary['total']);

        $allRows = $service->exportRows([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => 'less_than_1_hour',
        ]);

        $this->assertCount(1, $allRows);

        $missingRows = $service->exportRows([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => 'no_data',
            'data_status' => 'missing',
        ]);

        $this->assertCount(1, $missingRows);
        $this->assertSame('Excavator 02', $missingRows[0][2]);
        $this->assertSame('-', $missingRows[0][7]);
        $this->assertSame('-', $missingRows[0][8]);
        $this->assertSame('-', $missingRows[0][9]);
        $this->assertSame('Məlumat yoxdur', $missingRows[0][12]);
    }

    public function test_extended_filters_apply_to_efficiency_modal_rows(): void
    {
        $projectA = Project::query()->create(['name' => 'Lachin yol', 'active' => true]);
        $projectB = Project::query()->create(['name' => 'Fuzuli Agdam yol', 'active' => true]);
        $bakhoe = EquipmentType::query()->create(['name' => 'Bakhoe Loader']);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);

        $target = $this->equipment('Alpha Backhoe', $bakhoe, $projectA, Equipment::OWNERSHIP_NWC, '10-AA-111', '9001001');
        $otherProject = $this->equipment('Project B Backhoe', $bakhoe, $projectB, Equipment::OWNERSHIP_NWC, '10-AA-222', '9001002');
        $otherType = $this->equipment('Alpha Excavator', $excavator, $projectA, Equipment::OWNERSHIP_NWC, '10-AA-333', '9001003');

        $this->stat($target, '2026-07-01', 2.5, 1.25);
        $this->stat($otherProject, '2026-07-01', 2.5, 1.25);
        $this->stat($otherType, '2026-07-01', 2.5, 1.25);

        $rows = app(FleetEfficiencyService::class)->paginate([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'project_ids' => [$projectA->id],
            'vehicle_types' => ['bakhoe-loader'],
            'has_overtime' => 'yes',
            'day_hours_min' => 2,
            'day_hours_max' => 3,
            'overtime_hours_min' => 1,
            'total_hours_max' => 4,
            'search' => '9001001',
            'sort' => 'total_hours',
            'direction' => 'desc',
            'per_page' => 20,
        ]);

        $this->assertSame(1, $rows->total());
        $this->assertSame('Alpha Backhoe', $rows->items()[0]['name']);
        $this->assertSame('Backhoe Loader', $rows->items()[0]['vehicle_type']);
    }

    public function test_extended_filters_support_missing_data_and_specific_search_fields(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Road Roller']);
        $withData = $this->equipment('Roller With Data', $type, $project, Equipment::OWNERSHIP_ICARE, '77-RR-001', '81001');
        $missing = $this->equipment('Silent Roller', $type, $project, Equipment::OWNERSHIP_ICARE, '77-RR-002', '81002');

        $this->stat($withData, '2026-07-01', 6);

        $rows = app(FleetEfficiencyService::class)->paginate([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'data_status' => 'missing',
            'unit_name' => 'Silent',
            'registration_number' => 'RR-002',
            'wialon_id' => '81002',
            'per_page' => 20,
        ]);

        $this->assertSame(1, $rows->total());
        $this->assertSame('Silent Roller', $rows->items()[0]['name']);
        $this->assertSame('Məlumat yoxdur', $rows->items()[0]['data_status']);
    }

    private function equipment(
        string $name,
        EquipmentType $type,
        Project $project,
        string $ownership = Equipment::OWNERSHIP_NWC,
        ?string $registrationNumber = null,
        ?string $wialonId = null
    ): Equipment {
        return Equipment::query()->create([
            'name' => $name,
            'registration_number' => $registrationNumber,
            'wialon_unit_id' => $wialonId ?: (string) random_int(100000, 999999),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownership,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);
    }

    private function stat(
        Equipment $equipment,
        string $date,
        float $hours,
        ?float $overtimeHours = 0.0
    ): void {
        $totalHours = $overtimeHours === null ? null : $hours + $overtimeHours;
        $dataAvailable = $overtimeHours !== null;

        EquipmentDailyStat::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'daytime_hours' => $hours,
            'overtime_hours' => $overtimeHours,
            'total_hours' => $totalHours,
            'day_status' => app(FleetEfficiencyService::class)->efficiencyStatusForHours($hours, $totalHours),
            'has_overtime' => $overtimeHours === null ? null : $overtimeHours > 0,
            'data_available' => $dataAvailable,
            'daytime_data_available' => true,
            'overtime_data_available' => $overtimeHours !== null,
            'distance_km' => 0,
            'calculation_source' => 'wialon_shift_report',
            'calculation_status' => $dataAvailable ? 'ok' : 'shift_unknown',
        ]);
    }
}
