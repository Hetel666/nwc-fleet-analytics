<?php

namespace Tests\Feature;

use App\Jobs\FinalizeHistoricalRecalculationJob;
use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\Equipment;
use App\Models\EquipmentType;
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

        $nwcGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '100',
            'name' => 'Test project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $icareGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '101',
            'name' => 'Test project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $this->equipment($project, $nwcGroup, Equipment::OWNERSHIP_NWC, '1000');
        $this->equipment($project, $icareGroup, Equipment::OWNERSHIP_ICARE, '1001');

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
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

    public function test_preview_for_top20_section_has_no_aggregate_tasks(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Top project', 'active' => true]);

        $nwcGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '200',
            'name' => 'Top project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $icareGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '201',
            'name' => 'Top project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $this->equipment($project, $nwcGroup, Equipment::OWNERSHIP_NWC, '2000');
        $this->equipment($project, $icareGroup, Equipment::OWNERSHIP_ICARE, '2001');

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
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
                'aggregate_tasks' => 0,
                'total_tasks' => 6,
            ]);
    }

    public function test_preview_for_geofence_section_counts_projects_once(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Geofence project', 'active' => true]);

        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '300',
            'name' => 'Geofence project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '301',
            'name' => 'Geofence project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                'operation' => 'fetch_and_recalculate',
                'scope' => 'selected_projects',
                'project_ids' => [$project->id],
                'force' => false,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 3,
                'project_groups' => 1,
                'fetch_tasks' => 3,
                'aggregate_tasks' => 0,
                'total_tasks' => 3,
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

    public function test_preview_skips_project_ownership_groups_without_active_dashboard_equipment(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Partially empty project', 'active' => true]);

        $nwcGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '400',
            'name' => 'Partially empty project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '401',
            'name' => 'Partially empty project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $this->equipment($project, $nwcGroup, Equipment::OWNERSHIP_NWC, '4000');

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-28',
                'date_to' => '2026-07-28',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 1,
                'project_groups' => 1,
                'fetch_tasks' => 1,
                'aggregate_tasks' => 1,
                'total_tasks' => 2,
            ]);
    }

    public function test_historical_fetch_reports_are_queued_one_by_one(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Queued project', 'active' => true]);

        $nwcGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '100',
            'name' => 'Queued project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $icareGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '101',
            'name' => 'Queued project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $this->equipment($project, $nwcGroup, Equipment::OWNERSHIP_NWC, '5000');
        $this->equipment($project, $icareGroup, Equipment::OWNERSHIP_ICARE, '5001');

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

    private function equipment(
        Project $project,
        ProjectWialonGroup $group,
        string $ownershipType,
        string $wialonUnitId
    ): Equipment {
        $equipmentType = EquipmentType::query()->firstOrCreate(['name' => 'Excavator']);

        return Equipment::query()->create([
            'name' => 'Unit '.$wialonUnitId,
            'wialon_unit_id' => $wialonUnitId,
            'equipment_type_id' => $equipmentType->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => $group->wialon_group_id,
            'ownership_type' => $ownershipType,
            'active' => true,
        ]);
    }
}
