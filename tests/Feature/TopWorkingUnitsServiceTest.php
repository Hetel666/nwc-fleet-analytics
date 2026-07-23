<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\TopWorkingUnitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopWorkingUnitsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_top_twenty_daily_rows_and_excludes_disallowed_types(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $pickup = EquipmentType::query()->create(['name' => 'Pickup']);

        $allowed = $this->equipment('Excavator 01', $excavator, $project);
        $excluded = $this->equipment('Pickup 01', $pickup, $project);

        $this->stat($allowed, '2026-07-01', 0);
        $this->stat($allowed, '2026-07-02', 8);
        $this->stat($excluded, '2026-07-01', 20);

        $rows = app(TopWorkingUnitsService::class)->least([
            'from' => '2026-07-01',
            'to' => '2026-07-02',
        ], 20);

        $this->assertCount(2, $rows);
        $this->assertSame([0.0, 8.0], array_column($rows, 'hours'));
        $this->assertSame(['2026-07-01', '2026-07-02'], array_column($rows, 'date'));
    }

    public function test_it_sorts_by_hours_name_and_wialon_id(): void
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

        $this->stat($unit, '2026-07-01', 4);

        $rows = app(TopWorkingUnitsService::class)->most([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], 20);

        $this->assertCount(1, $rows);
        $this->assertSame('Backhoe Loader', $rows[0]['type']);
    }

    public function test_date_filter_includes_both_boundaries_and_datetime_values(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $unit = $this->equipment('Loader 01', $loader, $project);

        $this->rawStat($unit, '2026-06-30 23:59:59', 1);
        $this->rawStat($unit, '2026-07-01 08:00:00', 2);
        $this->rawStat($unit, '2026-07-03 23:59:59', 3);
        $this->rawStat($unit, '2026-07-04 00:00:00', 4);

        $rows = app(TopWorkingUnitsService::class)->least([
            'from' => '2026-07-01',
            'to' => '2026-07-03',
        ], 20);

        $this->assertSame(['2026-07-01', '2026-07-03'], array_column($rows, 'date'));
        $this->assertSame([2.0, 3.0], array_column($rows, 'hours'));
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

    private function stat(Equipment $equipment, string $date, float $hours): void
    {
        EquipmentDailyStat::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'distance_km' => 0,
            'calculation_status' => 'success',
        ]);
    }

    private function rawStat(Equipment $equipment, string $date, float $hours): void
    {
        DB::table('equipment_daily_stats')->insert([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'distance_km' => 0,
            'calculation_status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
