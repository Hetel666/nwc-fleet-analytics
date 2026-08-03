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
use App\Support\DashboardFilterState;
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

    public function test_equipment_drilldown_shows_data_without_unused_tabs(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        foreach ([
            'dashboardDrilldownTabData',
            'dashboardDrilldownTabSummary',
            'dashboardDrilldownTabFilters',
            'dashboardDrilldownFilterPanel',
            'dashboardDrilldownChips',
            'dashboardDrilldownFormula',
            'data-drilldown-tab-target',
            'data-drilldown-tab-section',
        ] as $unusedTabElement) {
            $this->assertStringNotContainsString($unusedTabElement, $html);
        }

        foreach ([
            'id="dashboardDrilldownBack"',
            'id="dashboardDrilldownSearch"',
            'id="dashboardDrilldownStatus"',
            'id="dashboardDrilldownTable"',
            'id="dashboardDrilldownPagination"',
            'id="dashboardDrilldownExport"',
        ] as $preservedElement) {
            $this->assertStringContainsString($preservedElement, $html);
        }

        $this->assertLessThan(
            strpos($html, 'id="dashboardDrilldownTable"'),
            strpos($html, 'id="dashboardDrilldownStatus"')
        );
    }

    public function test_viewer_can_open_dashboard_but_not_admin_pages(): void
    {
        $this->seed(DemoSeeder::class);

        $viewer = User::where('email', 'viewer@example.com')->firstOrFail();

        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $this->actingAs($viewer)->get('/projects')->assertForbidden();
    }

    public function test_viewer_can_be_limited_to_selected_dashboard_sections(): void
    {
        $this->seed(DemoSeeder::class);

        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
            'dashboard_sections' => [User::DASHBOARD_SECTION_EFFICIENCY],
        ]);

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-active-tab="efficiency"', false)
            ->assertSee('sidebar-subnav', false)
            ->assertSee('Ümumi 24 saat')
            ->assertSee('Gündüz növbəsi')
            ->assertSee('Gecə növbəsi')
            ->assertSee('Gecə gün daxilində')
            ->assertSee('Orta göstəricilər')
            ->assertSee('TOP20 az / çox işləyən')
            ->assertDontSee('dashboard-tabs', false)
            ->assertDontSee('data-dashboard-tab="', false);

        $this->actingAs($viewer)
            ->get(route('dashboard', ['tab' => User::DASHBOARD_SECTION_OVERVIEW]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('dashboard.tabs.show', ['tab' => User::DASHBOARD_SECTION_GEOZONES]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->getJson(route('api.dashboard.efficiency.summary'))
            ->assertOk();

        $this->actingAs($viewer)
            ->getJson(route('dashboard.geofence-violations.drilldown'))
            ->assertForbidden();
    }

    public function test_dashboard_sidebar_preserves_selected_period_between_sections(): void
    {
        $user = User::factory()->create(['active' => true]);
        $query = [
            'period' => 'custom',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
        ];

        $html = $this->actingAs($user)
            ->get(route('dashboard', [...$query, 'tab' => User::DASHBOARD_SECTION_OVERVIEW]))
            ->assertOk()
            ->getContent();

        foreach ([User::DASHBOARD_SECTION_OVERVIEW, User::DASHBOARD_SECTION_EFFICIENCY, User::DASHBOARD_SECTION_GEOZONES] as $section) {
            $this->assertStringContainsString(
                'href="'.e(route('dashboard', [...$query, 'tab' => $section])).'" data-dashboard-nav-link',
                $html
            );
        }

        $this->assertStringContainsString('fleet_dashboard_filters:'.$user->id, $html);
        $this->assertStringNotContainsString('period=yesterday&amp;tab=efficiency', $html);
        $this->assertStringNotContainsString('date_from='.now(config('app.timezone'))->subDay()->toDateString().'&amp;date_to=', $html);
    }

    public function test_dashboard_quick_period_buttons_hide_latest_completed_and_custom(): void
    {
        $user = User::factory()->create(['active' => true]);
        $html = $this->actingAs($user)
            ->get(route('dashboard', [
                'period' => 'custom',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-02',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-period="today"', $html);
        $this->assertStringNotContainsString('data-period="custom"', $html);
        $this->assertStringNotContainsString(__('app.period_latest_completed'), $html);
        $this->assertStringNotContainsString(__('app.period_custom'), $html);

        foreach (['yesterday', 'last_7_days', 'this_month', 'last_month'] as $period) {
            $this->assertStringContainsString('data-period="'.$period.'"', $html);
        }

        $this->assertStringContainsString('name="date_from" value="2026-08-01"', $html);
        $this->assertStringContainsString('name="date_to" value="2026-08-02"', $html);
        $this->assertStringContainsString('name="period" id="dashboardPeriodInput" value="custom"', $html);
    }

    public function test_dashboard_legacy_last_completed_quick_range_keeps_dates_without_active_button(): void
    {
        $user = User::factory()->create(['active' => true]);
        $html = $this->actingAs($user)
            ->get(route('dashboard', [
                'quick_range' => 'last_completed_day',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-02',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="date_from" value="2026-08-01"', $html);
        $this->assertStringContainsString('name="date_to" value="2026-08-02"', $html);
        $this->assertStringContainsString('name="period" id="dashboardPeriodInput" value="custom"', $html);
        $this->assertStringNotContainsString('btn-primary dashboard-period-button', $html);
        $this->assertStringNotContainsString('data-period="today"', $html);
        $this->assertStringNotContainsString('data-period="custom"', $html);
    }

    public function test_dashboard_uses_remembered_period_when_url_has_no_dates(): void
    {
        $user = User::factory()->create(['active' => true]);
        $cookie = rawurlencode(json_encode([
            (string) $user->id => [
                'period' => 'yesterday',
                'quick_range' => 'yesterday',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-01',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->withUnencryptedCookie(DashboardFilterState::COOKIE_NAME, $cookie)
            ->actingAs($user)
            ->get(route('dashboard', ['tab' => User::DASHBOARD_SECTION_EFFICIENCY]))
            ->assertOk()
            ->assertSee('name="date_from" value="2026-08-01"', false)
            ->assertSee('name="date_to" value="2026-08-01"', false)
            ->assertSee('name="period" id="dashboardPeriodInput" value="yesterday"', false);
    }

    public function test_dashboard_does_not_use_another_users_remembered_period(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);
        $cookie = rawurlencode(json_encode([
            (string) $otherUser->id => [
                'period' => 'custom',
                'quick_range' => 'custom',
                'date_from' => '2030-02-03',
                'date_to' => '2030-02-04',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->withUnencryptedCookie(DashboardFilterState::COOKIE_NAME, $cookie)
            ->actingAs($user)
            ->get(route('dashboard', ['tab' => User::DASHBOARD_SECTION_OVERVIEW]))
            ->assertOk()
            ->assertDontSee('value="2030-02-03"', false)
            ->assertDontSee('value="2030-02-04"', false);
    }

    public function test_admin_can_assign_dashboard_section_access_to_viewer(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);

        $this->actingAs($admin)
            ->put(route('users.update', $viewer), [
                'name' => $viewer->name,
                'email' => $viewer->email,
                'role' => User::ROLE_VIEWER,
                'active' => '1',
                'dashboard_sections_present' => '1',
                'dashboard_sections' => [
                    User::DASHBOARD_SECTION_EFFICIENCY,
                    User::DASHBOARD_SECTION_GEOZONES,
                ],
            ])
            ->assertRedirect(route('users.index'));

        $this->assertSame(
            [User::DASHBOARD_SECTION_EFFICIENCY, User::DASHBOARD_SECTION_GEOZONES],
            $viewer->fresh()->dashboard_sections
        );
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
        $this->assertStringContainsString('İdarə Paneli', $html);
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

    public function test_project_comparison_is_fully_expanded_without_inner_scroll_on_initial_page_load(): void
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

        $this->assertStringContainsString('dashboard-project-comparison-content', $html);
        $this->assertStringContainsString('dashboard-project-comparison-chart-wrapper', $html);
        $this->assertStringContainsString('dashboard-project-comparison-table-wrapper', $html);
        $this->assertStringContainsString('Expanded project 11', $html);
        $this->assertMatchesRegularExpression('/projectComparisonLabels.*Expanded project 11/s', $html);
        $this->assertStringNotContainsString('data-expandable="project-comparison"', $html);
        $this->assertStringNotContainsString('data-expand-toggle="project-comparison"', $html);
        $this->assertStringNotContainsString('class="expandable-extra"', $html);
        $this->assertStringNotContainsString('aria-controls="dashboardExpandableProjectComparison"', $html);
        $this->assertStringNotContainsString('>Gizlət</button>', $html);
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
