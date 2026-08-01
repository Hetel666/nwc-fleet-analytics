<?php

namespace Tests\Feature;

use App\Models\EfficiencyDailyFact;
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

    public function test_only_admin_sees_object_list_sync_button(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $viewer = User::where('email', 'viewer@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Obyekt siyahısını yenilə');

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Obyekt siyahısını yenilə');
    }

    public function test_dashboard_renders_azerbaijani_text_without_mojibake(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Geofence Transferləri', $html);
        $this->assertStringContainsString('Öz layihəsinin geozonasından kənarda olan texnikalar', $html);
        $this->assertStringNotContainsString('Cari geozona / layihə üzrə statistika', $html);

        foreach (['Ã', 'Ð', 'Р ', 'РЎ', 'Pё', 'SeP', 'вЂ'] as $marker) {
            $this->assertStringNotContainsString($marker, $html);
        }
    }

    public function test_efficiency_card_shows_five_engine_hour_statuses_without_percentages(): void
    {
        $this->seed(DemoSeeder::class);
        $project = $this->createPreparedEfficiencyRow();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $html = $this->actingAs($admin)
            ->get('/dashboard?tab=efficiency&project_id='.$project->id.'&date_from=2026-07-19&date_to=2026-07-19')
            ->assertOk()
            ->getContent();
        $chart = strpos($html, 'id="projectWorkCategoriesNwc"');
        $start = $chart === false ? false : strrpos(substr($html, 0, $chart), '<section');
        $end = $chart === false ? false : strpos($html, '</section>', $chart);
        $this->assertNotFalse($chart);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $card = substr($html, $start, $end - $start + strlen('</section>'));

        $this->assertStringNotContainsString('Faiz', $card);
        $this->assertStringNotContainsString('%</td>', $card);
        $this->assertStringContainsString('data-drilldown-view="projects"', $card);
        $this->assertStringContainsString('data-drilldown-mode="efficiency_projects"', $card);

        $positions = array_map(
            fn (string $label): int|false => strpos($card, $label),
            [
                '0 - 1 saat arası işləyən',
                '1 - 7 saat arası işləyən',
                '7 - 10 saat arası işləyən',
                '10 saatdan artıq işləyən',
                'İşləməyən / Məlumatı olmayan',
                'Cəmi',
            ]
        );
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    public function test_project_comparison_is_expanded_on_initial_page_load(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Excavator']);

        foreach (range(1, 11) as $index) {
            $project = Project::query()->create([
                'name' => sprintf('Expanded project %02d', $index),
                'code' => sprintf('EXP-%02d', $index),
                'active' => true,
            ]);
            $group = ProjectWialonGroup::query()->create([
                'project_id' => $project->id,
                'wialon_group_id' => '6099'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'name' => $project->name.' - NWC',
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'is_active' => true,
            ]);

            Equipment::query()->create([
                'name' => sprintf('Expanded unit %02d', $index),
                'wialon_unit_id' => 'expanded-'.$index,
                'equipment_type_id' => $type->id,
                'project_id' => $project->id,
                'project_wialon_group_id' => $group->id,
                'matched_wialon_group_id' => $group->wialon_group_id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'active' => true,
            ]);
        }

        Cache::flush();
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-expandable="project-comparison"', $html);
        $this->assertStringContainsString('data-expanded="1"', $html);
        $this->assertStringContainsString('class="dashboard-scroll-table is-expanded"', $html);
        $this->assertStringContainsString('class="expandable-extra"', $html);
        $this->assertStringNotContainsString('class="expandable-extra d-none"', $html);
        $this->assertStringContainsString('aria-controls="dashboardExpandableProjectComparison"', $html);
        $this->assertStringContainsString('>Gizlət</button>', $html);
    }

    public function test_project_comparison_shows_ownership_and_grand_totals(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $project = Project::query()->create(['name' => 'Totals project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601709803',
            'name' => 'Totals project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Totals type']);
        Equipment::query()->create([
            'name' => 'Totals unit',
            'wialon_unit_id' => 'totals-unit',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
        Cache::flush();
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('dashboard-project-comparison-table', $html);
        $this->assertStringContainsString('dashboard-project-comparison-total', $html);
        $this->assertStringContainsString('data-project-comparison-total-nwc=', $html);
        $this->assertStringContainsString('data-project-comparison-total-icare=', $html);
        $this->assertStringContainsString('data-project-comparison-total=', $html);
        $this->assertMatchesRegularExpression('/<th class="text-end">Cəmi<\/th>/', $html);
    }

    public function test_project_comparison_uses_two_level_drilldown_without_ownership_override(): void
    {
        $this->seed(DemoSeeder::class);

        $project = Project::query()->create(['name' => 'Two-level drilldown project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601709999',
            'name' => 'Two-level drilldown group',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'is_active' => true,
        ]);
        $type = EquipmentType::query()->create(['name' => 'Two-level drilldown type']);
        Equipment::query()->create([
            'name' => 'Two-level drilldown unit',
            'wialon_unit_id' => '709999',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'matched_wialon_group_id' => $group->wialon_group_id,
            'active' => true,
        ]);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-drilldown-view="equipment_types"', $html);
        $this->assertStringContainsString('data-drilldown-mode="project_types"', $html);
        $this->assertStringContainsString('id="dashboardDrilldownBack"', $html);
        $this->assertStringContainsString('dashboard-project-type-number', $html);
        $this->assertStringContainsString('dashboard-project-type-total', $html);
        $this->assertStringContainsString('id="dashboardDrilldownColgroup"', $html);
        $this->assertMatchesRegularExpression(
            '/data-drilldown-project-id="[^"]+"\s+data-drilldown-ownership-scope="project_groups"\s+data-drilldown-view="equipment_types"/',
            $html
        );
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
                'status' => '0_1',
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

        EfficiencyDailyFact::query()->create([
            'business_date' => '2026-07-19',
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'unit_name' => $equipment->name,
            'vehicle_type' => 'Loader',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 0.5,
            'engine_seconds' => 1800,
            'engine_hours_raw' => '0.50',
            'efficiency_status' => '0_1',
            'source_report_template_id' => 19,
            'source_report_name' => 'Qrup report Engine hours (api)',
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
