<?php

namespace Tests\Feature;

use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardPerformanceProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_profile_service_records_read_only_metrics(): void
    {
        config(['fleet.dashboard.cache_minutes' => 0]);
        config(['cache.default' => 'array']);

        $dashboard = app(DashboardService::class);
        $dashboard->getDashboardProfile([
            'date_from' => '2026-07-23',
            'date_to' => '2026-07-23',
        ]);
        $profile = $dashboard->dashboardPerformanceProfile();

        $this->assertSame('dashboard.profile', $profile['operation'] ?? null);
        $this->assertArrayHasKey('duration_ms', $profile);
        $this->assertArrayHasKey('query_count', $profile);
        $this->assertArrayHasKey('db_time_ms', $profile);
        $this->assertArrayHasKey('peak_memory_mb', $profile);
        $this->assertArrayHasKey('result_size_kb', $profile);
        $this->assertNotEmpty($profile['segments'] ?? []);
    }

    public function test_dashboard_profile_service_can_profile_single_widget(): void
    {
        config(['fleet.dashboard.cache_minutes' => 0]);
        config(['cache.default' => 'array']);

        $dashboard = app(DashboardService::class);
        $dashboard->getDashboardProfileWidget([
            'date_from' => '2026-07-23',
            'date_to' => '2026-07-23',
        ], 'overview');
        $profile = $dashboard->dashboardPerformanceProfile();
        $segmentNames = collect($profile['segments'] ?? [])->pluck('name')->all();

        $this->assertSame('dashboard.profileWidget', $profile['operation'] ?? null);
        $this->assertContains('widget.overview', $segmentNames);
    }

    public function test_dashboard_profile_command_rejects_unknown_widget(): void
    {
        config(['cache.default' => 'array']);

        $this->artisan('dashboard:profile', [
            '--widget' => 'unknown-widget',
        ])->assertExitCode(1);
    }

    public function test_dashboard_profile_command_runs_for_known_widget(): void
    {
        config(['fleet.dashboard.cache_minutes' => 0]);
        config(['cache.default' => 'array']);

        $this->artisan('dashboard:profile', [
            '--date-from' => '2026-07-23',
            '--date-to' => '2026-07-23',
            '--widget' => 'overview',
        ])->assertSuccessful();
    }

    public function test_dashboard_cache_hit_does_not_rebuild_payload(): void
    {
        config([
            'cache.default' => 'array',
            'fleet.dashboard.cache_minutes' => 10,
            'fleet.dashboard.cache_lock_wait_seconds' => 1,
        ]);
        Cache::flush();

        $dashboard = app(DashboardService::class);
        $filters = [
            'date_from' => '2026-07-23',
            'date_to' => '2026-07-23',
        ];

        $dashboard->getDashboardProfile($filters);
        $firstSegments = collect($dashboard->dashboardPerformanceProfile()['segments'] ?? [])->pluck('name');

        $dashboard->getDashboardProfile($filters);
        $secondSegments = collect($dashboard->dashboardPerformanceProfile()['segments'] ?? [])->pluck('name');

        $this->assertContains('buildDashboard', $firstSegments->all());
        $this->assertNotContains('buildDashboard', $secondSegments->all());
        $this->assertContains('cache.get', $secondSegments->all());
    }

    public function test_dashboard_tab_builds_only_shared_kpis_and_selected_tab_widgets(): void
    {
        config(['fleet.dashboard.cache_minutes' => 0]);

        $data = app(DashboardService::class)->getDashboardTab([
            'date_from' => '2026-07-23',
            'date_to' => '2026-07-23',
        ], 'efficiency');

        $this->assertEqualsCanonicalizing([
            'overview',
            'projectActualWorkHourCategoriesByOwnership',
            'daytimeEfficiency',
            'dailyAverageDashboards',
            'leastWorking',
            'mostWorking',
        ], array_keys($data));
        $this->assertArrayNotHasKey('geofenceViolations', $data);
        $this->assertArrayNotHasKey('projectOwnershipComparison', $data);
    }
}
