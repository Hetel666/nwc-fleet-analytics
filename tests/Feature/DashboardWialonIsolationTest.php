<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardWialonIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_page_uses_local_data_without_wialon(): void
    {
        Cache::flush();
        config(['fleet.wialon.live_dashboard_reports' => true]);

        [$project] = $this->createLocalDashboardDataset();
        $this->mockWialonNeverCalled();

        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($user)
            ->get(route('dashboard', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_dashboard_export_uses_local_data_without_wialon(): void
    {
        Cache::flush();
        config(['fleet.wialon.live_dashboard_reports' => true]);

        [$project] = $this->createLocalDashboardDataset();
        $this->mockWialonNeverCalled();

        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($user)
            ->get(route('dashboard.export', [
                'block' => 'actual-work-hours-nwc',
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_dashboard_service_uses_local_average_metrics_without_wialon(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);

        [$project] = $this->createLocalDashboardDataset();
        $this->mockWialonNeverCalled();

        $result = app(DashboardService::class)->getAverageMetricsByOwnership([
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-19',
            'project_id' => $project->id,
        ]);

        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(5.5, $result[Equipment::OWNERSHIP_NWC]['total_hours']);
        $this->assertSame('Local stats', $result[Equipment::OWNERSHIP_NWC]['source']);
    }

    private function createLocalDashboardDataset(): array
    {
        $project = Project::query()->create(['name' => 'Local Dashboard Project', 'active' => true]);
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
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'success',
        ]);

        return [$project, $equipment];
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
