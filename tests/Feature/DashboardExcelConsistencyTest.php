<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use App\Services\DashboardFleetDrilldownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardExcelConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_drilldown_excel_export_uses_same_service_selection(): void
    {
        $project = Project::query()->create(['name' => 'Excel Project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $equipment = Equipment::query()->create([
            'name' => 'Excel Unit',
            'wialon_unit_id' => 'excel-1',
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
            'worked_hours' => 6,
            'distance_km' => 3,
            'utilization_percent' => 60,
            'calculation_source' => 'local_test',
            'calculation_status' => 'success',
        ]);
        $service = app(DashboardFleetDrilldownService::class);
        $filters = $service->filters([
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-19',
            'project_id' => $project->id,
            'ownership' => 'nwc',
        ]);

        $this->assertSame(1, $service->getUnits($filters)->total());
        $this->assertCount(1, $service->export($filters)['sections'][0]['rows']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]))
            ->get(route('dashboard.drilldown.units.export', $filters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
