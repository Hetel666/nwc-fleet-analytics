<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\FleetOwnershipStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetOwnershipStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_real_project_groups_only(): void
    {
        [$project, $type, $nwcGroup, $icareGroup] = $this->fixtures();

        $this->equipment($project, $type, $nwcGroup, 'NWC 1', '1');
        $this->equipment($project, $type, $icareGroup, 'ICARE 1', '2');
        $this->equipmentWithoutProjectGroup($project, $type, 'Service only', '3');

        $summary = app(FleetOwnershipStatsService::class)->summary();

        $this->assertSame(1, $summary['nwc_count']);
        $this->assertSame(1, $summary['icare_count']);
        $this->assertSame(2, $summary['total_count']);
        $this->assertSame(50.0, $summary['nwc_percent']);
        $this->assertSame(50.0, $summary['icare_percent']);
    }

    public function test_summary_handles_only_nwc_only_icare_and_empty_data(): void
    {
        [$project, $type, $nwcGroup, $icareGroup] = $this->fixtures();
        $service = app(FleetOwnershipStatsService::class);

        $this->assertSame(0, $service->summary()['total_count']);

        $this->equipment($project, $type, $nwcGroup, 'NWC 1', '1');
        $this->assertSame(1, $service->summary()['nwc_count']);
        $this->assertSame(0, $service->summary()['icare_count']);

        Equipment::query()->delete();
        $this->equipment($project, $type, $icareGroup, 'ICARE 1', '2');
        $this->assertSame(0, $service->summary()['nwc_count']);
        $this->assertSame(1, $service->summary()['icare_count']);
    }

    public function test_percentages_are_rounded_and_sum_to_100(): void
    {
        [$project, $type, $nwcGroup, $icareGroup] = $this->fixtures();

        $this->equipment($project, $type, $nwcGroup, 'NWC 1', '1');
        $this->equipment($project, $type, $nwcGroup, 'NWC 2', '2');
        $this->equipment($project, $type, $icareGroup, 'ICARE 1', '3');

        $summary = app(FleetOwnershipStatsService::class)->summary();

        $this->assertSame(66.7, $summary['nwc_percent']);
        $this->assertSame(33.3, $summary['icare_percent']);
        $this->assertSame(100.0, $summary['nwc_percent'] + $summary['icare_percent']);
    }

    public function test_export_rows_match_unique_units_and_can_be_filtered(): void
    {
        [$project, $type, $nwcGroup, $icareGroup] = $this->fixtures();

        $this->equipment($project, $type, $nwcGroup, 'NWC 1', '1');
        $this->equipment($project, $type, $icareGroup, 'ICARE 1', '2');

        $service = app(FleetOwnershipStatsService::class);
        $allRows = $service->export()['sections'][0]['rows'];
        $nwcRows = $service->export([], 'nwc')['sections'][0]['rows'];
        $icareRows = $service->export([], 'icare')['sections'][0]['rows'];

        $this->assertCount(2, $allRows);
        $this->assertCount(1, $nwcRows);
        $this->assertCount(1, $icareRows);
        $this->assertSame('Yuxarı Şirvan LOT1 - NWC', $nwcRows[0][3]);
        $this->assertSame('Yuxarı Şirvan LOT1 - İcarə', $icareRows[0][3]);
    }

    private function fixtures(): array
    {
        $project = Project::create(['name' => 'Yuxarı Şirvan LOT1', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);
        $nwcGroup = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701930',
            'name' => 'Yuxarı Şirvan LOT1 - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $icareGroup = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701933',
            'name' => 'Yuxarı Şirvan LOT1 - İcarə',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        return [$project, $type, $nwcGroup, $icareGroup];
    }

    private function equipment(Project $project, EquipmentType $type, ProjectWialonGroup $group, string $name, string $unitId): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => $unitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => $group->ownership_type,
        ]);
    }

    private function equipmentWithoutProjectGroup(Project $project, EquipmentType $type, string $name, string $unitId): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => $unitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
    }
}
