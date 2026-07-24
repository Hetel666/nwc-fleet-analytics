<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_and_read_dashboard_layout_without_wialon(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.layout.save'), [
                'widgets' => [
                    ['key' => 'most-working', 'order' => 10, 'visible' => true],
                    ['key' => 'least-working', 'order' => 20, 'visible' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'most-working'])
            ->assertJsonFragment(['key' => 'least-working']);

        $this->assertDatabaseHas('settings', [
            'key' => 'dashboard.layout.default',
            'is_secret' => false,
        ]);

        $this->actingAs($admin)
            ->getJson(route('dashboard.layout'))
            ->assertOk()
            ->assertJsonPath('widgets.0.key', 'most-working');
    }

    public function test_viewer_cannot_save_dashboard_layout(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);

        $this->actingAs($viewer)
            ->postJson(route('dashboard.layout.save'), [
                'widgets' => [
                    ['key' => 'most-working', 'order' => 10, 'visible' => true],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, Setting::query()->count());
    }
}
