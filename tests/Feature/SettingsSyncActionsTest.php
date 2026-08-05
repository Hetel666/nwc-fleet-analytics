<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class SettingsSyncActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_unit_sync_redirects_with_success_message(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('fleet:sync-units')
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Synced 2 Wialon units.\n");

        $this->actingAs($this->admin())
            ->from(route('settings.edit'))
            ->post(route('settings.sync-units'))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('status', 'Synced 2 Wialon units.');

        $this->assertDatabaseHas('settings', [
            'key' => 'auto_sync_units_last_status',
            'value' => 'success',
        ]);
    }

    public function test_viewer_can_run_manual_unit_sync_from_dashboard(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('fleet:sync-units')
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Synced 2 Wialon units.\n");

        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER,
            'active' => true,
        ]);

        $this->actingAs($viewer)
            ->from(route('dashboard'))
            ->post(route('settings.sync-units'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Synced 2 Wialon units.');

        $this->actingAs($viewer)
            ->get(route('settings.edit'))
            ->assertForbidden();
    }

    public function test_manual_unit_sync_redirects_with_error_message_when_command_fails(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('fleet:sync-units')
            ->andThrow(new RuntimeException('Wialon token is not configured.'));

        $this->actingAs($this->admin())
            ->from(route('settings.edit'))
            ->post(route('settings.sync-units'))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('error', 'Wialon token is not configured.');

        $this->assertDatabaseHas('settings', [
            'key' => 'auto_sync_units_last_status',
            'value' => 'failed',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);
    }
}
