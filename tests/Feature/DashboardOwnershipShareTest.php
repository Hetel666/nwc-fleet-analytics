<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOwnershipShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_ownership_share_uses_equipment_count_when_hours_are_empty(): void
    {
        $project = Project::create(['name' => 'Yuxarı Şirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

        Equipment::create([
            'name' => 'NWC Unit',
            'wialon_unit_id' => '1',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        Equipment::create([
            'name' => 'Icare Unit',
            'wialon_unit_id' => '2',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $overview = app(DashboardService::class)->getOverview([
            'project_id' => $project->id,
            'from' => '2026-07-01',
            'to' => '2026-07-11',
        ]);

        $this->assertSame([
            ['label' => Equipment::OWNERSHIP_ICARE, 'count' => 1],
            ['label' => Equipment::OWNERSHIP_NWC, 'count' => 1],
        ], $overview['ownership_share']);
    }

    public function test_equipment_type_distribution_is_split_by_ownership(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $truck = EquipmentType::create(['name' => 'Truck']);
        $crane = EquipmentType::create(['name' => 'Crane']);

        $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC Excavator 1');
        $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC Excavator 2');
        $this->equipment($project, $truck, Equipment::OWNERSHIP_NWC, 'NWC Truck');
        $this->equipment($project, $crane, Equipment::OWNERSHIP_ICARE, 'Icare Crane 1');
        $this->equipment($project, $crane, Equipment::OWNERSHIP_ICARE, 'Icare Crane 2');

        $result = app(DashboardService::class)->getEquipmentTypeDistributionByOwnership([
            'project_id' => $project->id,
            'from' => '2026-07-01',
            'to' => '2026-07-11',
        ]);

        $this->assertSame([
            ['name' => 'Excavator', 'total' => 2],
            ['name' => 'Truck', 'total' => 1],
        ], $result[Equipment::OWNERSHIP_NWC]);
        $this->assertSame([
            ['name' => 'Crane', 'total' => 2],
        ], $result[Equipment::OWNERSHIP_ICARE]);
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
}
