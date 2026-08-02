<?php

namespace Tests\Feature;

use App\Models\DashboardConfigurationAuditLog;
use App\Models\User;
use App\Services\DashboardDisplayConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_visibility_management_requires_permission(): void
    {
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
            'permissions' => [User::PERMISSION_DASHBOARD_VISIBILITY_MANAGE],
        ]);

        $this->actingAs($viewer)
            ->get(route('admin.dashboard-visibility.index'))
            ->assertForbidden();

        $this->actingAs($manager)
            ->get(route('admin.dashboard-visibility.index'))
            ->assertOk()
            ->assertSee('Dashboard idaretmesi');
    }

    public function test_admin_can_hide_dashboard_without_removing_it_from_registry(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson(route('api.admin.dashboard-visibility.update', 'ownership_share'), [
                'is_visible' => false,
                'display_order' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('dashboard.code', 'ownership_share')
            ->assertJsonPath('dashboard.is_visible', false);

        $response = $this->actingAs($admin)
            ->getJson(route('api.dashboard.display-configuration'))
            ->assertOk()
            ->assertJsonMissing(['code' => 'ownership_share']);

        $this->assertArrayNotHasKey('updated_by', $response->json('dashboards.0'));

        $this->assertFalse(app(DashboardDisplayConfigurationService::class)->isWidgetVisible('ownership-share'));
        $this->assertDatabaseHas('dashboard_configuration_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'dashboard_hidden',
            'entity_type' => 'dashboard',
            'entity_code' => 'ownership_share',
        ]);
    }

    public function test_hidden_dashboard_is_not_rendered_on_dashboard_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson(route('api.admin.dashboard-visibility.update', 'ownership_share'), [
                'is_visible' => false,
                'display_order' => 10,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('dashboard', ['tab' => 'overview']))
            ->assertOk()
            ->assertDontSee('data-dashboard-widget="ownership-share"', false);
    }

    public function test_hidden_efficiency_status_is_filtered_and_direct_request_is_forbidden(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson(route('api.admin.dashboard-status-visibility.update', [
                'dashboardType' => 'general_efficiency',
                'statusCode' => '0_1',
            ]), [
                'is_visible' => false,
            ])
            ->assertOk()
            ->assertJsonPath('status.status_code', '0_1')
            ->assertJsonPath('status.is_visible', false);

        $response = $this->actingAs($admin)
            ->getJson(route('api.dashboard.display-configuration'))
            ->assertOk();

        $this->assertFalse(
            collect($response->json('statuses.general_efficiency'))
                ->contains(fn (array $status): bool => $status['status_code'] === '0_1')
        );

        $this->actingAs($admin)
            ->getJson(route('api.dashboard.efficiency.units', ['status' => '0_1']))
            ->assertForbidden();
    }

    public function test_reset_restores_defaults_and_adds_audit_entry(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson(route('api.admin.dashboard-visibility.update', 'top_20_low'), [
                'is_visible' => false,
                'display_order' => 510,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('api.admin.dashboard-visibility.reset'))
            ->assertOk()
            ->assertJsonFragment(['code' => 'top_20_low', 'is_visible' => true]);

        $this->assertTrue(app(DashboardDisplayConfigurationService::class)->isDashboardVisible('top_20_low'));
        $this->assertTrue(DashboardConfigurationAuditLog::query()->where('action', 'configuration_reset')->exists());
    }
}
