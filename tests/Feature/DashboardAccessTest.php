<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_dashboard_and_admin_pages(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/projects')->assertOk();
        $this->actingAs($admin)->get('/equipment')->assertOk();
    }

    public function test_viewer_can_open_dashboard_but_not_admin_pages(): void
    {
        $this->seed(DemoSeeder::class);

        $viewer = User::where('email', 'viewer@example.com')->firstOrFail();

        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $this->actingAs($viewer)->get('/projects')->assertForbidden();
    }
}
