<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_ui_api_routes_are_registered_and_authorized(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->get(route('dashboard.drilldown.units'))
            ->assertOk()
            ->assertJsonStructure(['title', 'filters', 'summary', 'columns', 'data', 'meta']);

        $this->actingAs($admin)
            ->get(route('dashboard.drilldown.units.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($admin)
            ->get(route('dashboard.layout'))
            ->assertOk()
            ->assertJsonStructure(['widgets']);

        $this->actingAs($admin)
            ->get(route('dashboard.ownership.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($admin)
            ->get(route('dashboard.top-working-units.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
