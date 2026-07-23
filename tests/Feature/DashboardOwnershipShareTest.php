<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOwnershipShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_ownership_share_uses_project_wialon_groups(): void
    {
        $project = Project::create(['name' => 'Yuxarı Şirvan LOT1', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);
        $nwcGroup = $this->projectGroup($project, '601701930', Equipment::OWNERSHIP_NWC, 'Yuxarı Şirvan LOT1 - NWC');
        $icareGroup = $this->projectGroup($project, '601701933', Equipment::OWNERSHIP_ICARE, 'Yuxarı Şirvan LOT1 - İcarə');

        $this->equipment($project, $type, $nwcGroup, 'NWC Unit', '1');
        $this->equipment($project, $type, $icareGroup, 'Icare Unit', '2');

        $overview = app(DashboardService::class)->getOverview([
            'project_id' => $project->id,
            'from' => '2026-07-01',
            'to' => '2026-07-11',
        ]);

        $this->assertSame([
            ['label' => Equipment::OWNERSHIP_NWC, 'count' => 1],
            ['label' => Equipment::OWNERSHIP_ICARE, 'count' => 1],
        ], $overview['ownership_share']);
    }

    public function test_equipment_type_distribution_is_split_by_ownership(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $truck = EquipmentType::create(['name' => 'Truck']);
        $crane = EquipmentType::create(['name' => 'Crane']);

        $this->equipmentWithoutGroup($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC Excavator 1');
        $this->equipmentWithoutGroup($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC Excavator 2');
        $this->equipmentWithoutGroup($project, $truck, Equipment::OWNERSHIP_NWC, 'NWC Truck');
        $this->equipmentWithoutGroup($project, $crane, Equipment::OWNERSHIP_ICARE, 'Icare Crane 1');
        $this->equipmentWithoutGroup($project, $crane, Equipment::OWNERSHIP_ICARE, 'Icare Crane 2');

        $result = app(DashboardService::class)->getEquipmentTypeDistributionByOwnership([
            'project_id' => $project->id,
            'from' => '2026-07-01',
            'to' => '2026-07-11',
        ]);

        $this->assertSame([
            ['id' => $excavator->id, 'name' => 'Excavator', 'total' => 2],
            ['id' => $truck->id, 'name' => 'Truck', 'total' => 1],
        ], $result[Equipment::OWNERSHIP_NWC]);
        $this->assertSame([
            ['id' => $crane->id, 'name' => 'Crane', 'total' => 2],
        ], $result[Equipment::OWNERSHIP_ICARE]);
    }

    private function projectGroup(Project $project, string $groupId, string $ownershipType, string $name): ProjectWialonGroup
    {
        return ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => $groupId,
            'name' => $name,
            'ownership_type' => $ownershipType,
        ]);
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
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
            'active' => true,
        ]);
    }

    private function equipmentWithoutGroup(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        $group = ProjectWialonGroup::query()->firstOrCreate(
            [
                'project_id' => $project->id,
                'ownership_type' => $ownershipType,
            ],
            [
                'wialon_group_id' => $ownershipType === Equipment::OWNERSHIP_NWC ? '601701935' : '601701936',
                'name' => $project->name.' '.$ownershipType,
            ]
        );

        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
            'ownership_type' => $ownershipType,
            'active' => true,
        ]);
    }
}
