<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureViewerAccess;
use App\Models\DashboardExport;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ViewerProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_save_own_theme_without_changing_another_users_preferences(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true, 'project_ids' => []]);
        $other = User::factory()->create(['role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->putJson(route('api.user.dashboard-preferences.update'), [
            'theme' => 'light', 'user_id' => $other->id,
        ])->assertOk()->assertJsonPath('theme', 'light');
        $this->assertDatabaseHas('user_dashboard_preferences', ['user_id' => $viewer->id, 'theme' => 'light']);
        $this->assertDatabaseMissing('user_dashboard_preferences', ['user_id' => $other->id]);
        $this->getJson(route('api.user.dashboard-preferences.show'))->assertOk()->assertJsonPath('theme', 'light');
        $this->postJson(route('dashboard.event-notes.store'), [])->assertForbidden();
    }

    public function test_viewer_cannot_mutate_even_with_legacy_permissions(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true, 'permissions' => User::permissionKeys()]);
        $this->actingAs($viewer);
        foreach (['dashboard.event-notes.store', 'settings.sync-units', 'api.wialon-catalog.sync', 'api.projects.store'] as $route) {
            $this->postJson(route($route), [])->assertForbidden();
        }
        $this->get('/projects')->assertForbidden();
        $this->assertFalse($viewer->hasPermission(User::PERMISSION_PROJECTS_MANAGE));
    }

    public function test_admin_can_assign_selected_projects(): void
    {
        $project = Project::create(['name' => 'Allowed project', 'active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Restricted viewer', 'email' => 'restricted@example.com', 'role' => 'viewer', 'active' => 1,
            'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
            'project_access' => 'selected', 'project_ids' => [$project->id],
            'permissions_present' => 1, 'permissions' => [User::PERMISSION_PROJECTS_MANAGE],
        ])->assertRedirect(route('users.index'));
        $viewer = User::where('email', 'restricted@example.com')->firstOrFail();
        $this->assertSame([$project->id], $viewer->project_ids);
        $this->assertSame([], $viewer->permissions);
        $this->get(route('users.edit', $viewer))->assertOk()->assertSee('project_ids[]', false);
    }

    public function test_restricted_viewer_cannot_request_other_project_or_old_export(): void
    {
        $allowed = Project::create(['name' => 'Allowed project', 'active' => true]);
        $other = Project::create(['name' => 'Other project', 'active' => true]);
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true, 'project_ids' => [$allowed->id]]);
        $this->actingAs($viewer);
        $this->assertSame([$allowed->id], Project::pluck('id')->all());
        foreach (['/dashboard', '/api/dashboard/efficiency/units', '/api/dashboard/monthly-efficiency/object-geofence-days', '/dashboard/export'] as $url) {
            $this->getJson($url.'?project_id='.$other->id)->assertForbidden();
        }
        $this->get('/projects/'.$other->id.'/dashboard')->assertNotFound();
        $this->get('/admin/wialon-catalog')->assertForbidden();
        $export = DashboardExport::create(['user_id' => $viewer->id, 'block' => 'overview', 'filters' => ['project_id' => $other->id], 'status' => 'pending']);
        $this->getJson(route('dashboard.exports.status', $export))->assertForbidden();
        $this->get(route('dashboard.exports.download', $export))->assertForbidden();
    }

    public function test_restricted_viewer_dashboard_lists_only_assigned_projects(): void
    {
        $this->seed(DemoSeeder::class);
        $projects = Project::orderBy('id')->get();
        $allowed = $projects->first();
        $other = $projects->last();
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true, 'project_ids' => [$allowed->id]]);
        $response = $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $this->assertSame($allowed->id, $response->viewData('filters')['project_id']);
        $this->assertSame([$allowed->id], $response->viewData('projects')->pluck('id')->all());
        $response->assertDontSee('<form method="POST" action="'.route('settings.sync-units').'"', false);
        $this->get('/dashboard?project_id='.$other->id)->assertForbidden();
    }

    public function test_restricted_requests_default_to_allowed_project(): void
    {
        $project = Project::create(['name' => 'Allowed project', 'active' => true]);
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true, 'project_ids' => [$project->id]]);
        $request = Request::create('/api/dashboard/efficiency/units?project_ids[]=999');
        $request->setUserResolver(fn () => $viewer);
        (new EnsureViewerAccess)->handle($request, function ($request) use ($project) {
            $this->assertSame($project->id, $request->query('project_id'));
            $this->assertSame([$project->id], $request->query('project_ids'));

            return response('ok');
        });
    }

    public function test_viewer_can_switch_between_multiple_projects_from_project_page(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $projects = Project::where('active', true)->orderBy('id')->get();
        $first = $projects[0];
        $second = $projects[1];
        $viewer = User::factory()->create(['role' => 'viewer', 'active' => true, 'project_ids' => [$first->id, $second->id]]);
        $response = $this->actingAs($viewer)->get(route('projects.dashboard', $first))->assertOk();
        $response->assertSee('select name="project_id"', false)
            ->assertSee('value="'.$second->id.'"', false)
            ->assertSee('action="'.route('dashboard').'"', false);
        $response = $this->get('/dashboard?project_id='.$second->id)->assertOk();
        $this->assertSame($second->id, $response->viewData('filters')['project_id']);
        $response->assertSee('select name="project_id"', false);
    }
}
