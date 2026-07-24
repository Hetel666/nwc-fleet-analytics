<?php

namespace Tests\Feature;

use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
