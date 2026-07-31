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
use App\Services\WialonReportStatsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            ->assertSee('Tarixi məlumatların yenilənməsi')
            ->assertSee('Geofence Pozuntuları');
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

    public function test_preview_for_efficiency_section_uses_project_ownership_tasks_without_aggregate(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Efficiency project', 'active' => true]);

        $nwcGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '250',
            'name' => 'Efficiency project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $icareGroup = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '251',
            'name' => 'Efficiency project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $this->equipment($project, $nwcGroup, Equipment::OWNERSHIP_NWC, '2500');
        $this->equipment($project, $icareGroup, Equipment::OWNERSHIP_ICARE, '2501');

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
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

    public function test_preview_for_geofence_violations_uses_separate_project_tasks(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Violation project', 'active' => true]);

        foreach ([
            ['id' => '310', 'ownership' => Equipment::OWNERSHIP_NWC],
            ['id' => '311', 'ownership' => Equipment::OWNERSHIP_ICARE],
        ] as $group) {
            ProjectWialonGroup::query()->create([
                'project_id' => $project->id,
                'wialon_group_id' => $group['id'],
                'name' => 'Violation project - '.$group['ownership'],
                'ownership_type' => $group['ownership'],
            ]);
        }

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-27',
                'date_to' => '2026-07-28',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 2,
                'project_groups' => 1,
                'fetch_tasks' => 1,
                'aggregate_tasks' => 0,
                'total_tasks' => 1,
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

    public function test_geofence_violation_manual_range_respects_report_limit(): void
    {
        config()->set('geofence_violations.max_report_period_days', 2);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-26',
                'date_to' => '2026-07-28',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
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

    public function test_historical_fetch_task_is_failed_when_section_command_returns_error(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Failed command project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '500',
            'name' => 'Failed command project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '5000');
        $service = app(HistoricalRecalculationService::class);
        $run = $service->createRun([
            'date_from' => '2026-07-28',
            'date_to' => '2026-07-28',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => true,
        ], $admin);
        $task = $run->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->firstOrFail();

        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Wialon report failed.');

        (new RunHistoricalRecalculationTaskJob($task->id))->handle(
            app(WialonReportStatsSyncService::class),
            $service
        );

        $this->assertSame(HistoricalRecalculationTask::STATUS_FAILED, $task->refresh()->status);
        $this->assertStringContainsString('exit code 1', (string) $task->error_message);
    }

    public function test_geofence_violations_history_uses_its_own_fetch_command(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Violation command project', 'active' => true]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '510',
            'name' => 'Violation command project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $service = app(HistoricalRecalculationService::class);
        $run = $service->createRun([
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-28',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => true,
        ], $admin);
        $task = $run->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->firstOrFail();

        Artisan::shouldReceive('call')
            ->once()
            ->with('fleet:sync-geofence-violations-report', \Mockery::on(
                fn (array $parameters): bool => $parameters['--project'] === $project->id
                    && $parameters['--from'] === '2026-07-27 00:00:00'
                    && $parameters['--to'] === '2026-07-28 23:59:59'
                    && $parameters['--force'] === true
            ))
            ->andReturn(0);

        (new RunHistoricalRecalculationTaskJob($task->id))->handle(
            app(WialonReportStatsSyncService::class),
            $service
        );

        $this->assertSame(HistoricalRecalculationTask::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_efficiency_history_runs_shift_sync_for_one_project_group(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Efficiency command project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '520',
            'name' => 'Efficiency command project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '5200');
        $service = app(HistoricalRecalculationService::class);
        $run = $service->createRun([
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => true,
        ], $admin);
        $task = $run->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->firstOrFail();

        Artisan::shouldReceive('call')
            ->once()
            ->with('fleet:plan-shift-sync', \Mockery::on(
                fn (array $parameters): bool => $parameters['--group'] === '520'
                    && $parameters['--from'] === '2026-07-29'
                    && $parameters['--to'] === '2026-07-29'
                    && $parameters['--force'] === true
            ))
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('fleet:run-shift-sync', \Mockery::on(
                fn (array $parameters): bool => $parameters['--group'] === '520'
                    && $parameters['--date'] === '2026-07-29'
                    && $parameters['--limit'] === 1
                    && $parameters['--retry-failed'] === true
            ))
            ->andReturn(0);

        (new RunHistoricalRecalculationTaskJob($task->id))->handle(
            app(WialonReportStatsSyncService::class),
            $service
        );

        $this->assertSame(HistoricalRecalculationTask::STATUS_COMPLETED, $task->refresh()->status);
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
