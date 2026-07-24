<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDrilldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_drilldown_units_use_local_dataset_and_filters(): void
    {
        [$project, $type] = $this->createDataset();
        $this->mockWialonNeverCalled();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
                'equipment_type_id' => $type->id,
                'ownership' => 'nwc',
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'Local NWC Excavator')
            ->assertJsonPath('data.0.worked_hours', 5.5);
    }

    public function test_drilldown_export_uses_same_local_service(): void
    {
        [$project, $type] = $this->createDataset();
        $this->mockWialonNeverCalled();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($user)
            ->get(route('dashboard.drilldown.units.export', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
                'equipment_type_id' => $type->id,
                'ownership' => 'nwc',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function createDataset(): array
    {
        $project = Project::query()->create(['name' => 'Local Project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $equipment = Equipment::query()->create([
            'name' => 'Local NWC Excavator',
            'registration_number' => '90-LC-001',
            'wialon_unit_id' => '990001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701935',
            'active' => true,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-19',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 5.5,
            'distance_km' => 12.3,
            'utilization_percent' => 55,
            'calculation_source' => 'local_test',
            'calculation_status' => 'success',
        ]);

        return [$project, $type, $equipment];
    }

    private function mockWialonNeverCalled(): void
    {
        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
            $mock->shouldReceive('findReportTemplateIdByName')->never();
            $mock->shouldReceive('getMessages')->never();
            $mock->shouldReceive('getUnits')->never();
            $mock->shouldReceive('getUnitGroups')->never();
            $mock->shouldReceive('getGeofenceGroupZones')->never();
        });
    }
}
