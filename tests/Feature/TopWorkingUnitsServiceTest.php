<?php

namespace Tests\Feature;

use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\TopWorkingUnitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopWorkingUnitsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_top_twenty_unit_day_rows_and_excludes_disallowed_types(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $pickup = EquipmentType::query()->create(['name' => 'Pickup']);

        $allowed = $this->equipment('Excavator 01', $excavator, $project);
        $dump = $this->equipment('Dump 01', $dumpTruck, $project);
        $excluded = $this->equipment('Pickup 01', $pickup, $project);

        $this->stat($allowed, '2026-07-01', 0);
        $this->stat($allowed, '2026-07-02', 8);
        $this->stat($dump, '2026-07-01', 1);
        $this->stat($excluded, '2026-07-01', 20);

        $rows = app(TopWorkingUnitsService::class)->least([
            'from' => '2026-07-01',
            'to' => '2026-07-02',
        ], 20);

        $this->assertCount(2, $rows);
        $this->assertSame([0.0, 8.0], array_column($rows, 'hours'));
        $this->assertSame(['2026-07-01', '2026-07-02'], array_column($rows, 'date'));
    }

    public function test_it_limits_top_working_rows_in_sql_order(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        for ($index = 1; $index <= 25; $index++) {
            $unit = $this->equipment(sprintf('Loader %02d', $index), $loader, $project, Equipment::OWNERSHIP_NWC, (string) (1000 + $index));
            $this->stat($unit, '2026-07-01', (float) $index);
        }

        $least = app(TopWorkingUnitsService::class)->least([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], 20);
        $most = app(TopWorkingUnitsService::class)->most([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], 20);

        $this->assertCount(20, $least);
        $this->assertCount(20, $most);
        $this->assertSame(range(1.0, 20.0), array_column($least, 'hours'));
        $this->assertSame(range(25.0, 6.0), array_column($most, 'hours'));
    }

    public function test_it_does_not_sum_days_between_each_other(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $unit = $this->equipment('Loader 01', $loader, $project, Equipment::OWNERSHIP_NWC, '100');

        $this->stat($unit, '2026-07-01', 8.4);
        $this->stat($unit, '2026-07-02', 6.7);
        $this->stat($unit, '2026-07-03', 9.1);

        $rows = app(TopWorkingUnitsService::class)->most([
            'from' => '2026-07-01',
            'to' => '2026-07-03',
        ], 20);

        $this->assertCount(3, $rows);
        $this->assertSame([9.1, 8.4, 6.7], array_column($rows, 'hours'));
    }

    public function test_it_sorts_by_hours_date_name_and_wialon_id(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $first = $this->equipment('A Loader', $loader, $project, Equipment::OWNERSHIP_NWC, '200');
        $second = $this->equipment('A Loader', $loader, $project, Equipment::OWNERSHIP_NWC, '100');
        $third = $this->equipment('B Loader', $loader, $project, Equipment::OWNERSHIP_NWC, '300');

        $this->stat($first, '2026-07-01', 5);
        $this->stat($second, '2026-07-01', 5);
        $this->stat($third, '2026-07-01', 5);

        $rows = app(TopWorkingUnitsService::class)->least([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], 20);

        $this->assertSame(['100', '200', '300'], array_column($rows, 'wialon_id'));
    }

    public function test_it_normalizes_bakhoe_loader_as_backhoe_loader(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $bakhoe = EquipmentType::query()->create(['name' => 'Bakhoe Loader']);
        $unit = $this->equipment('Bakhoe 01', $bakhoe, $project);

        $this->stat($unit, '2026-07-01', 4, 'Backhoe Loader');

        $rows = app(TopWorkingUnitsService::class)->most([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], 20);

        $this->assertCount(1, $rows);
        $this->assertSame('Backhoe Loader', $rows[0]['type']);
    }

    public function test_null_is_excluded_but_zero_is_valid_for_least_working(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $zero = $this->equipment('Zero Loader', $loader, $project, Equipment::OWNERSHIP_NWC, '100');
        $null = $this->equipment('Null Loader', $loader, $project, Equipment::OWNERSHIP_NWC, '200');

        $this->stat($zero, '2026-07-01', 0);
        $this->stat($null, '2026-07-01', null, 'Loader', 'engine_hours_null');

        $rows = app(TopWorkingUnitsService::class)->least([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], 20);

        $this->assertCount(1, $rows);
        $this->assertSame('100', $rows[0]['wialon_id']);
        $this->assertSame(0.0, $rows[0]['hours']);
    }

    public function test_project_and_ownership_filters_are_applied(): void
    {
        $projectA = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $projectB = Project::query()->create(['name' => 'Project B', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $a = $this->equipment('A', $loader, $projectA, Equipment::OWNERSHIP_NWC, '100');
        $b = $this->equipment('B', $loader, $projectB, Equipment::OWNERSHIP_ICARE, '200');

        $this->stat($a, '2026-07-01', 2);
        $this->stat($b, '2026-07-01', 9);

        $rows = app(TopWorkingUnitsService::class)->most([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'project_id' => $projectA->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ], 20);

        $this->assertCount(1, $rows);
        $this->assertSame('100', $rows[0]['wialon_id']);
    }

    public function test_modal_style_lowercase_ownership_filter_is_applied(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $nwc = $this->equipment('NWC Loader', $loader, $project, Equipment::OWNERSHIP_NWC, '100');
        $icare = $this->equipment('Icare Loader', $loader, $project, Equipment::OWNERSHIP_ICARE, '200');

        $this->stat($nwc, '2026-07-01', 2);
        $this->stat($icare, '2026-07-01', 9);

        $rows = app(TopWorkingUnitsService::class)->most([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
            'ownership' => 'nwc',
            'top_working_ranking' => 'most',
        ], 20);

        $this->assertCount(1, $rows);
        $this->assertSame('100', $rows[0]['wialon_id']);
    }

    private function equipment(
        string $name,
        EquipmentType $type,
        Project $project,
        string $ownership = Equipment::OWNERSHIP_NWC,
        ?string $wialonId = null
    ): Equipment {
        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => $wialonId ?? (string) random_int(100000, 999999),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownership,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);
    }

    private function stat(Equipment $equipment, string $date, ?float $hours, ?string $vehicleType = null, string $status = 'ok'): void
    {
        EngineHoursReportUnitDay::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'equipment_type_id' => $equipment->equipment_type_id,
            'ownership_type' => $equipment->ownership_type,
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'unit_name' => $equipment->name,
            'vehicle_type' => $vehicleType ?: $equipment->type?->name,
            'engine_hours' => $hours,
            'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
            'parse_status' => $status,
            'source_group_ids_json' => ['601701903'],
            'synced_at' => now(),
        ]);
    }
}
