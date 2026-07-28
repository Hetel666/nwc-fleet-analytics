<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_sources_page_shows_update_sections(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard-analytics.index'))
            ->assertOk()
            ->assertSee('Orta motosaat / Orta yürüş')
            ->assertSee('Top 20 az işləyənlər / Top 20 çox işləyənlər')
            ->assertSee('Geozonadan çıxma halları')
            ->assertSee('Tarixi məlumatların yenilənməsi')
            ->assertSee('fleet:sync-engine-hours-report')
            ->assertSee('fleet:sync-geozon-api');
    }
}
