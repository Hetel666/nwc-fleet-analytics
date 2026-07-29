<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\DashboardLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_layout_comes_from_dashboard_registry(): void
    {
        $layout = app(DashboardLayoutService::class)->getResolvedLayout();

        $this->assertSame('ownership-share', $layout[0]['key']);
        $this->assertTrue($layout[0]['visible']);
        $this->assertContains('project-comparison', collect($layout)->pluck('key')->all());
        $this->assertContains('geofence-violations-report', collect($layout)->pluck('key')->all());
    }

    public function test_admin_can_save_global_dashboard_layout(): void
    {
        $admin = $this->user('admin@example.com', User::ROLE_ADMIN);

        $payload = [
            'widgets' => [
                ['key' => 'equipment-types-nwc', 'width' => 4, 'title' => 'Texnika novleri: NWC', 'visible' => false],
                ['key' => 'ownership-share', 'width' => 4, 'title' => 'Mensubiyyet payi', 'visible' => true],
                ['key' => 'project-comparison', 'width' => 12],
            ],
        ];

        $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), $payload)
            ->assertOk();

        $setting = Setting::query()->where('key', config('dashboard.layout_setting_key'))->firstOrFail();
        $stored = json_decode((string) $setting->value, true);

        $this->assertSame('equipment-types-nwc', $stored['widgets'][0]['key']);
        $this->assertSame('Texnika novleri: NWC', $stored['widgets'][0]['title']);
        $this->assertFalse($stored['widgets'][0]['visible']);
        $this->assertTrue($stored['widgets'][1]['visible']);
        $this->assertSame($admin->id, $stored['updated_by']);
    }

    public function test_viewer_cannot_save_global_dashboard_layout(): void
    {
        $viewer = $this->user('viewer@example.com', User::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->putJson(route('dashboard.layout.update'), [
                'widgets' => [
                    ['key' => 'ownership-share', 'width' => 4],
                ],
            ])
            ->assertForbidden();
    }

    public function test_unknown_widget_key_is_rejected(): void
    {
        $admin = $this->user('admin2@example.com', User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'widgets' => [
                    ['key' => 'unknown-widget', 'width' => 4],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_reset_global_dashboard_layout(): void
    {
        $admin = $this->user('admin3@example.com', User::ROLE_ADMIN);
        Setting::query()->create([
            'key' => config('dashboard.layout_setting_key'),
            'value' => json_encode(['version' => 1, 'widgets' => [['key' => 'project-comparison', 'order' => 10, 'width' => 12, 'visible' => false]]]),
            'is_secret' => false,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('dashboard.layout.destroy'))
            ->assertOk();

        $this->assertDatabaseMissing('settings', [
            'key' => config('dashboard.layout_setting_key'),
        ]);
    }

    private function user(string $email, string $role): User
    {
        return User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'active' => true,
        ]);
    }
}
