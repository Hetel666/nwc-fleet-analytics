<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDashboardPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_sources_page_shows_only_current_block_formulas(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $configuredKeys = collect(config('dashboard_analytics.groups'))
            ->flatMap(fn (array $group): array => collect($group['blocks'] ?? [])->pluck('key')->all())
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(UserDashboardPreference::DASHBOARD_WIDGET_KEYS, $configuredKeys);

        $this->actingAs($admin)
            ->get(route('admin.dashboard-analytics.index'))
            ->assertOk()
            ->assertSee('13 aktiv blok')
            ->assertSee('Ümumi baxış')
            ->assertSee('Aylıq effektivlik - NWC üzrə')
            ->assertSee('Report for Aylıq effektivlik')
            ->assertSee('Kritik aşağı: MS &lt; D * 5 saat.', false)
            ->assertSee('Qrup date report Engine hours (api)')
            ->assertSee('business_date + project_id + wialon_unit_id')
            ->assertSee('Orta motosaat = ümumi motosaat / etibarlı texnika-gün sayı.')
            ->assertSee('Geofence Transferləri')
            ->assertSee('Geofence Pozuntuları')
            ->assertSee('monthly_efficiency_unit_geofence_facts')
            ->assertDontSee('Dashboard məntiqi')
            ->assertDontSee('Module contracts')
            ->assertDontSee('Safe resync scope')
            ->assertDontSee('Project binding')
            ->assertDontSee('Wialon əmri');
    }
}
