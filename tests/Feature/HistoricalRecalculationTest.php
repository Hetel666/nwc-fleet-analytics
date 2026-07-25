<?php

namespace Tests\Feature;

use App\Jobs\FinalizeHistoricalRecalculationJob;
use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\Equipment;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\HistoricalRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HistoricalRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_historical_recalculation_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.historical-recalculations.index'))
            ->assertOk()
            ->assertSee('Tarixi məlumatların yenilənməsi');
    }

    public function test_viewer_cannot_open_historical_recalculation_page(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);

        $this->actingAs($viewer)
            ->get(route('admin.historical-recalculations.index'))
            ->assertForbidden();
    }

    public function test_preview_counts_inclusive_days_and_project_ownership_groups(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Test project', 'active' => true]);

        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '100',
            'name' => 'Test project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '101',
            'name' => 'Test project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'operation' => 'fetch_and_recalculate',
                'scope' => 'selected_projects',
                'project_ids' => [$project->id],
                'force' => false,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 3,
                'project_groups' => 2,
                'fetch_tasks' => 6,
                'aggregate_tasks' => 1,
                'total_tasks' => 7,
            ]);
    }

    public function test_selected_projects_scope_requires_project_ids(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'operation' => 'fetch',
                'scope' => 'selected_projects',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_ids');
    }

    public function test_historical_fetch_reports_are_queued_one_by_one(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Queued project', 'active' => true]);

        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '100',
            'name' => 'Queued project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '101',
            'name' => 'Queued project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $run = app(HistoricalRecalculationService::class)->createRun([
            'date_from' => '2026-07-20',
            'date_to' => '2026-07-21',
            'timezone' => 'Asia/Baku',
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => false,
        ], $admin);

        $this->assertSame(5, $run->tasks()->count());
        $this->assertSame(4, $run->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->count());
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);
        Queue::assertNotPushed(FinalizeHistoricalRecalculationJob::class);

        $firstTask = $run->tasks()
            ->where('operation', HistoricalRecalculation::OPERATION_FETCH)
            ->orderBy('stat_date')
            ->orderBy('project_id')
            ->orderBy('ownership_type')
            ->firstOrFail();
        $firstTask->forceFill(['status' => HistoricalRecalculationTask::STATUS_COMPLETED])->save();

        app(HistoricalRecalculationService::class)->dispatchNextPendingFetchTask($run->refresh());

        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 2);
    }
}
