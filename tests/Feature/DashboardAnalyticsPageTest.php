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
            ->assertSee('Dashboard məntiqi')
            ->assertSee('Ümumi baxış')
            ->assertSee('Effektivlik - ümumi gün')
            ->assertSee('dashboard-reports:sync-daily')
            ->assertSee('historical-recalculations database worker')
            ->assertSee('Orta motosaat / Orta yürüş')
            ->assertSee('Geofence Transferləri')
            ->assertSee('Geofence Pozuntuları')
            ->assertSee('Tarixi məlumatların yenilənməsi')
            ->assertSee('fleet:sync-geozon-api')
            ->assertSee('fleet:sync-geofence-violations-report')
            ->assertSee('Module contracts')
            ->assertSee('monthly_efficiency')
            ->assertSee('monthly_efficiency_unit_geofence_facts')
            ->assertSee('partially_isolated');
    }
}
