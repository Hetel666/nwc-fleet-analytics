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

class DashboardTopWorkingExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_working_export_uses_dashboard_local_service_without_wialon(): void
    {
        $project = Project::query()->create(['name' => 'Top Project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $equipment = Equipment::query()->create([
            'name' => 'Top Unit',
            'wialon_unit_id' => 'top-1',
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
            'worked_hours' => 9,
            'distance_km' => 2,
            'utilization_percent' => 90,
            'calculation_source' => 'local_test',
            'calculation_status' => 'success',
        ]);
        $this->mockWialonNeverCalled();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]))
            ->get(route('dashboard.top-working-units.export', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'ranking' => 'most',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function mockWialonNeverCalled(): void
    {
        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
            $mock->shouldReceive('findReportTemplateIdByName')->never();
            $mock->shouldReceive('getMessages')->never();
        });
    }
}
