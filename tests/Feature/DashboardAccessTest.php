<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\WialonService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_dashboard_and_admin_pages(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/projects')->assertOk();
        $this->actingAs($admin)->get('/equipment')->assertOk();
    }

    public function test_viewer_can_open_dashboard_but_not_admin_pages(): void
    {
        $this->seed(DemoSeeder::class);

        $viewer = User::where('email', 'viewer@example.com')->firstOrFail();

        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $this->actingAs($viewer)->get('/projects')->assertForbidden();
    }

    public function test_dashboard_renders_azerbaijani_text_without_mojibake(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Geozonadan çıxma halları', $html);
        $this->assertStringContainsString('Öz layihəsinin geozonasından kənarda olan texnikalar', $html);

        foreach (['Ã', 'Ð', 'Р ', 'РЎ', 'Pё', 'SeP', 'вЂ'] as $marker) {
            $this->assertStringNotContainsString($marker, $html);
        }
    }

    public function test_dashboard_reads_prepared_data_without_live_wialon_reports(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);
        $this->seed(DemoSeeder::class);

        $project = Project::query()->firstOrFail();
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Test Wialon group',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard?project_id='.$project->id)
            ->assertOk();
    }

    public function test_dashboard_cache_miss_and_wialon_outage_still_use_local_data(): void
    {
        config([
            'fleet.dashboard.cache_minutes' => 10,
            'fleet.wialon.live_dashboard_reports' => true,
        ]);
        Cache::flush();
        $this->seed(DemoSeeder::class);

        $project = Project::query()->firstOrFail();
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Unavailable Wialon group',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $this->mockUnavailableWialon();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard?project_id='.$project->id)
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_dashboard_modal_endpoint_does_not_call_wialon(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);
        $this->seed(DemoSeeder::class);
        $this->createPreparedEfficiencyRow();
        $this->mockUnavailableWialon();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'ownership' => 'nwc',
                'status' => 'less_than_1',
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'Prepared NWC Loader');
    }

    public function test_dashboard_excel_export_does_not_call_wialon(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);
        $this->seed(DemoSeeder::class);
        $project = $this->createPreparedEfficiencyRow();
        $this->mockUnavailableWialon();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('dashboard.export', [
                'block' => 'actual-work-hours-nwc',
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function createPreparedEfficiencyRow(): Project
    {
        $project = Project::query()->create(['name' => 'Prepared Local Project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        $equipment = Equipment::query()->create([
            'name' => 'Prepared NWC Loader',
            'registration_number' => '90-PR-001',
            'wialon_unit_id' => '99001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Prepared local group',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-19',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 0.5,
            'daytime_hours' => 0.5,
            'overtime_hours' => 0,
            'total_hours' => 0.5,
            'day_status' => 'less_than_1_hour',
            'has_overtime' => false,
            'data_available' => true,
            'daytime_data_available' => true,
            'overtime_data_available' => true,
            'distance_km' => 1,
            'calculation_source' => 'wialon_shift_report',
            'calculation_status' => 'ok',
        ]);

        return $project;
    }

    private function mockUnavailableWialon(): void
    {
        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
            $mock->shouldReceive('getReportData')->never();
            $mock->shouldReceive('getReportRows')->never();
            $mock->shouldReceive('executeReport')->never();
            $mock->shouldReceive('getMessages')->never();
            $mock->shouldReceive('getUnits')->never();
            $mock->shouldReceive('getUnit')->never();
            $mock->shouldReceive('getUnitGroups')->never();
            $mock->shouldReceive('getGeofences')->never();
            $mock->shouldReceive('getGeofenceZonesByIds')->never();
            $mock->shouldReceive('getUnitLastPosition')->never();
            $mock->shouldReceive('getReportResources')->never();
            $mock->shouldReceive('findReportTemplateByName')->never();
            $mock->shouldReceive('findReportTemplateIdByName')->never();
            $mock->shouldReceive('selectReportResultRows')->never();
            $mock->shouldReceive('getReportResultRows')->never();
            $mock->shouldReceive('getReportResultSubrows')->never();
            $mock->shouldReceive('cleanupReportResult')->never();
            $mock->shouldReceive('calculateUnitDailyData')->never();
        });
    }
}
