<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDashboardPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_preferences_receives_defaults(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)
            ->getJson(route('api.user.dashboard-preferences.show'))
            ->assertOk()
            ->assertExactJson(UserDashboardPreference::defaults());
    }

    public function test_user_can_save_layout_and_theme(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)
            ->putJson(route('api.user.dashboard-preferences.update'), [
                'layout' => 'compact',
                'theme' => 'dark',
            ])
            ->assertOk()
            ->assertJson([
                'layout' => 'compact',
                'theme' => 'dark',
                'density' => 'comfortable',
            ]);

        $this->assertDatabaseHas('user_dashboard_preferences', [
            'user_id' => $user->id,
            'layout' => 'compact',
            'theme' => 'dark',
        ]);
    }

    public function test_preferences_are_isolated_per_user(): void
    {
        $first = User::factory()->create(['active' => true]);
        $second = User::factory()->create(['active' => true]);

        $this->actingAs($first)->putJson(route('api.user.dashboard-preferences.update'), [
            'layout' => 'side_filters',
            'sidebar_state' => 'collapsed',
        ])->assertOk();

        $this->actingAs($second)
            ->getJson(route('api.user.dashboard-preferences.show'))
            ->assertExactJson(UserDashboardPreference::defaults());
    }

    public function test_invalid_layout_returns_422_and_does_not_persist(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)
            ->putJson(route('api.user.dashboard-preferences.update'), [
                'layout' => '<script>alert(1)</script>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout');

        $this->assertDatabaseCount('user_dashboard_preferences', 0);
    }

    public function test_delete_resets_preferences(): void
    {
        $user = User::factory()->create(['active' => true]);
        UserDashboardPreference::query()->create([
            'user_id' => $user->id,
            'layout' => 'card_grid',
            'theme' => 'light',
            'density' => 'dense',
            'sidebar_state' => 'collapsed',
            'donut_legend_position' => 'hidden',
            'table_density' => 'dense',
            'kpi_size' => 'small',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('api.user.dashboard-preferences.destroy'))
            ->assertOk()
            ->assertExactJson(UserDashboardPreference::defaults());

        $this->assertDatabaseCount('user_dashboard_preferences', 0);
    }

    public function test_dashboard_renders_standard_by_default_and_all_layout_options(): void
    {
        $user = User::factory()->create(['active' => true]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('data-dashboard-layout-variant="standard"', false)
            ->assertSee('Dizayn ayarları');

        foreach (UserDashboardPreference::LAYOUTS as $layout) {
            $response->assertSee('value="'.$layout.'"', false);
        }
    }
}
