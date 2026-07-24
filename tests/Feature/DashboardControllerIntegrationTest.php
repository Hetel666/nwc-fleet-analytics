<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardControllerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_page_uses_local_data_and_exposes_layout_context(): void
    {
        $project = Project::query()->create(['name' => 'Dashboard Local Project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $equipment = Equipment::query()->create([
            'name' => 'Dashboard Local Unit',
            'wialon_unit_id' => 'dashboard-local-1',
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
            'worked_hours' => 5,
            'distance_km' => 2,
            'utilization_percent' => 50,
            'calculation_source' => 'local_test',
            'calculation_status' => 'success',
        ]);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldNotReceive('executeReport');
            $mock->shouldNotReceive('getReportRows');
            $mock->shouldNotReceive('getReportTablesRows');
            $mock->shouldNotReceive('getMessages');
        });

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]))
            ->get(route('dashboard', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertViewHas('dashboardLayout')
            ->assertSee('Dashboard Local Project');

        Mockery::close();
    }
}
