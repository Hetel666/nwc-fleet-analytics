<?php

namespace Tests\Feature;

use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use App\Models\UserDashboardPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardEfficiencyOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_efficiency_sections_and_cards_are_rendered_in_the_required_order(): void
    {
        $html = $this->efficiencyDashboardHtml();

        $this->assertAppearsInOrder($html, [
            'id="efficiency-general"',
            'data-widget-key="project-work-categories-nwc"',
            'data-widget-key="project-work-categories-icare"',
            'id="efficiency-daytime"',
            'Effektivlik gündüz: NWC üzrə',
            'Effektivlik gündüz: İcarə üzrə',
            'id="efficiency-nighttime"',
            'Effektivlik gecə: NWC üzrə',
            'Effektivlik gecə: İcarə üzrə',
            'id="efficiency-averages"',
            'data-widget-key="average-engine-hours"',
            'data-widget-key="average-mileage"',
            'id="efficiency-top20"',
            'data-widget-key="least-working"',
            'data-widget-key="most-working"',
        ]);

        $this->assertStringContainsString('Seçilmiş dövr üçün məlumat yoxdur', $html);
        $this->assertStringContainsString('Seçilmiş dövr üçün gecə növbəsi məlumatı yoxdur', $html);
        $this->assertStringContainsString('Qrup report Engine hours (api)', $html);
        $this->assertStringContainsString('day report Engine hours (api)', $html);
        $this->assertStringContainsString('night report Engine hours (api)', $html);
    }

    public function test_efficiency_order_is_identical_for_every_dashboard_layout(): void
    {
        $user = User::factory()->create(['active' => true]);

        foreach (UserDashboardPreference::LAYOUTS as $layout) {
            UserDashboardPreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                [...UserDashboardPreference::defaults(), 'layout' => $layout],
            );

            $html = $this->actingAs($user)
                ->get(route('dashboard', ['tab' => 'efficiency']))
                ->assertOk()
                ->assertSee('data-dashboard-layout-variant="'.$layout.'"', false)
                ->getContent();

            $this->assertAppearsInOrder($html, [
                'id="efficiency-general"',
                'id="efficiency-daytime"',
                'id="efficiency-nighttime"',
                'id="efficiency-averages"',
                'id="efficiency-top20"',
            ], $layout);
        }
    }

    public function test_efficiency_top_search_is_removed_without_affecting_exports_and_drilldown(): void
    {
        $user = User::factory()->create(['active' => true]);
        $html = $this->actingAs($user)->get(route('dashboard', [
            'tab' => 'efficiency',
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'ownership_type' => 'NWC',
            'daytime_search' => '10-AF-106',
            'nighttime_search' => '10-AF-106',
        ]))->assertOk()->getContent();

        foreach ([
            'name="date_from" value="2026-07-31"',
            'name="date_to" value="2026-07-31"',
            'name="ownership_type"',
            '/api/dashboard/daytime-efficiency/export',
            '/api/dashboard/nighttime-efficiency/export',
            'id="dashboardDrilldownSearch"',
            'block=least-working',
            'block=most-working',
        ] as $marker) {
            $this->assertTrue(str_contains($html, $marker), 'Missing preserved dashboard contract: '.$marker);
        }

        foreach ([
            'id="daytime-efficiency-search"',
            'id="nighttime-efficiency-search"',
            'name="daytime_search"',
            'name="nighttime_search"',
            'data-drilldown-search=',
            'daytimeEfficiencySearch',
            'nighttimeEfficiencySearch',
            'search=10-AF-106',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $html, 'Removed efficiency top search marker is still rendered: '.$marker);
        }

        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));
        $this->assertTrue(
            str_contains($view, "drilldown_mode: 'efficiency_projects'"),
            'The efficiency drill-down contract must remain in the dashboard script.',
        );
    }

    public function test_top20_ranking_tables_render_twenty_rows_without_fixed_vertical_scroll(): void
    {
        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Long Road Project With Tooltip', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Road Grader']);

        for ($index = 1; $index <= 25; $index++) {
            $unit = Equipment::query()->create([
                'name' => sprintf('Long Named Top Unit %02d', $index),
                'wialon_unit_id' => 'top20-'.$index,
                'equipment_type_id' => $type->id,
                'project_id' => $project->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'matched_wialon_group_id' => '601701903',
                'active' => true,
            ]);

            EngineHoursReportUnitDay::query()->create([
                'stat_date' => '2026-07-31',
                'equipment_id' => $unit->id,
                'project_id' => $project->id,
                'equipment_type_id' => $type->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'wialon_unit_id' => $unit->wialon_unit_id,
                'unit_name' => $unit->name,
                'vehicle_type' => $type->name,
                'engine_hours' => (float) $index,
                'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
                'parse_status' => 'ok',
                'source_group_ids_json' => ['601701903'],
                'synced_at' => now(),
            ]);
        }

        $html = $this->actingAs($user)->get(route('dashboard', [
            'tab' => 'efficiency',
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
        ]))->assertOk()->getContent();

        $this->assertSame(20, substr_count($html, 'data-drilldown-top-ranking="least"'));
        $this->assertSame(20, substr_count($html, 'data-drilldown-top-ranking="most"'));
        $this->assertStringContainsString('class="dashboard-scroll-table dashboard-ranking-table top20-table-wrapper"', $html);
        $this->assertStringContainsString('class="table table-sm align-middle mb-0 top20-table"', $html);
        $this->assertStringContainsString('title="Long Road Project With Tooltip"', $html);

        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));
        $this->assertStringNotContainsString('height: 350px', $view);
        $this->assertStringNotContainsString('max-height: 350px', $view);
        $this->assertStringNotContainsString('height: 320px', $view);
        $this->assertStringNotContainsString('max-height: 320px', $view);
        $this->assertStringContainsString('overflow-y: visible', $view);
    }

    public function test_internal_navigation_only_scrolls_to_existing_sections(): void
    {
        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));
        $handlerStart = strpos($view, "document.getElementById('dashboardEfficiencySubnav')?.addEventListener");
        $handlerEnd = strpos($view, 'applyDashboardPreferences(savedDashboardPreferences);', $handlerStart);

        $this->assertNotFalse($handlerStart);
        $this->assertNotFalse($handlerEnd);
        $handler = substr($view, $handlerStart, $handlerEnd - $handlerStart);

        $this->assertAppearsInOrder($view, [
            'data-efficiency-section="general"',
            'data-efficiency-section="daytime"',
            'data-efficiency-section="nighttime"',
            'data-efficiency-section="averages"',
            'data-efficiency-section="top20"',
        ]);
        $this->assertStringContainsString('target.scrollIntoView', $handler);
        $this->assertStringNotContainsString('fetch(', $handler);
        $this->assertStringNotContainsString('.submit(', $handler);
        $this->assertStringNotContainsString('window.location', $handler);
    }

    private function efficiencyDashboardHtml(): string
    {
        $user = User::factory()->create(['active' => true]);

        return $this->actingAs($user)
            ->get(route('dashboard', ['tab' => 'efficiency']))
            ->assertOk()
            ->getContent();
    }

    /** @param list<string> $needles */
    private function assertAppearsInOrder(string $haystack, array $needles, string $context = ''): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle, $offset);
            $this->assertNotFalse($position, ($context ? $context.': ' : '').'Missing or out-of-order marker: '.$needle);
            $offset = $position + strlen($needle);
        }
    }
}
