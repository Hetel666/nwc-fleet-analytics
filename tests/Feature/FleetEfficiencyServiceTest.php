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

        $this->assertSame(1, $summary['less_than_1']);
        $this->assertSame(0, $summary['from_1_to_7']);
        $this->assertSame(1, $summary['from_7_to_10']);
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

        $this->assertSame(1, $summary['from_1_to_7']);
        $this->assertSame(1, $summary['from_7_to_10']);
        $this->assertSame(1, $summary['less_than_1']);
        $this->assertSame(1, $summary['missing_data']);
        $this->assertSame(3, $summary['total']);
    }

    public function test_overtime_is_independent_from_daytime_status(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        $equipment = $this->equipment('Loader 01', $type, $project);

        $this->stat($equipment, '2026-07-01', 6, '2026-07-01 11:00:00', '2026-07-01 20:00:00');

        $summary = app(FleetEfficiencyService::class)->summaryForOwnership([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $summary['from_7_to_10']);
        $this->assertSame(1, $summary['overtime']);
        $this->assertSame(2, $summary['total']);
    }

    public function test_missing_data_is_included_in_less_than_one_hour_and_can_be_filtered(): void
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

        $this->assertSame(2, $summary['less_than_1']);
        $this->assertSame(1, $summary['missing_data']);
        $this->assertSame(2, $summary['total']);

        $allRows = $service->exportRows([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => 'less_than_1',
        ]);

        $this->assertCount(2, $allRows);

        $missingRows = $service->exportRows([
            'from' => '2026-07-01',
            'to' => '2026-07-01',
            'work_category' => 'less_than_1',
            'data_status' => 'missing',
        ]);

        $this->assertCount(1, $missingRows);
        $this->assertSame('Excavator 02', $missingRows[0][2]);
        $this->assertSame('—', $missingRows[0][7]);
        $this->assertSame('—', $missingRows[0][8]);
        $this->assertSame('—', $missingRows[0][9]);
        $this->assertSame('Məlumat yoxdur', $missingRows[0][12]);
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

    private function stat(
        Equipment $equipment,
        string $date,
        float $hours,
        ?string $firstMessageAt = null,
        ?string $lastMessageAt = null
    ): void {
        EquipmentDailyStat::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'distance_km' => 0,
            'first_message_at' => $firstMessageAt,
            'last_message_at' => $lastMessageAt,
        ]);
    }
}
