<?php

namespace Tests\Unit;

use Tests\TestCase;

class DarkThemeRenderingTest extends TestCase
{
    public function test_application_layout_exposes_complete_theme_contract(): void
    {
        $source = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('color-scheme: dark;', $source);
        $this->assertStringContainsString('--fleet-chart-text:', $source);
        $this->assertStringContainsString('--fleet-chart-grid:', $source);
        $this->assertStringContainsString('--fleet-chart-border:', $source);
        $this->assertStringContainsString('fleet:theme-change', $source);
        $this->assertStringContainsString("toggle.setAttribute('aria-pressed'", $source);
        $this->assertStringContainsString('.nav-tabs .nav-link.active', $source);
    }

    public function test_dashboard_charts_and_geofence_widgets_use_theme_colors(): void
    {
        $source = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $this->assertStringContainsString('const dashboardChartTheme = () =>', $source);
        $this->assertStringContainsString('const configureDashboardChartTheme = () =>', $source);
        $this->assertStringContainsString('Object.values(Chart.instances || {}).forEach(chart => chart.destroy());', $source);
        $this->assertStringContainsString('window.addEventListener(\'fleet:theme-change\'', $source);
        $this->assertStringContainsString('ctx.fillStyle = dashboardChartTheme().text;', $source);
        $this->assertStringContainsString('borderColor: dashboardChartTheme().border', $source);
        $this->assertStringContainsString('background: var(--fleet-card);', $source);
        $this->assertStringContainsString('background: var(--fleet-card-soft);', $source);
        $this->assertStringNotContainsString('const applyDashboardChartTheme = chart =>', $source);
        $this->assertStringNotContainsString('registerDashboardChart(new Chart', $source);
        $this->assertStringNotContainsString("ctx.fillStyle = '#0f1f3a';", $source);
        $this->assertStringNotContainsString('.geofence-report-donut::after {'."\n".'        content: "";'."\n".'        position: absolute;'."\n".'        inset: 22%;'."\n".'        border-radius: 50%;'."\n".'        background: #fff;', $source);
    }

    public function test_dashboard_sources_page_uses_shared_theme_variables(): void
    {
        $source = file_get_contents(resource_path('views/admin/dashboard-analytics/index.blade.php'));

        $this->assertStringContainsString('background: var(--fleet-card);', $source);
        $this->assertStringContainsString('background: var(--fleet-card-soft);', $source);
        $this->assertStringContainsString('color: var(--fleet-ink);', $source);
        $this->assertStringContainsString('color: var(--fleet-muted);', $source);
        $this->assertStringNotContainsString('background: #fff;', $source);
    }
}
