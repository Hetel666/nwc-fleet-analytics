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
use App\Models\Setting;
use App\Models\User;
use App\Services\DashboardReportPipelineService;
use App\Services\DashboardResyncDryRunPlanner;
use App\Services\EfficiencyRecalculationHandler;
use App\Services\HistoricalRecalculationModuleRegistry;
use App\Services\HistoricalRecalculationService;
use App\Support\GeofenceExcludedGroups;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HistoricalRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_duplicate_task_scope_with_nullable_dimensions(): void
    {
        $run = HistoricalRecalculation::query()->create([
            'uuid' => 'c779f782-70a9-4fc5-b564-c87d9507c227',
            'signature' => 'duplicate-nullable-task-scope-test',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'timezone' => 'Asia/Baku',
            'status' => HistoricalRecalculation::STATUS_PENDING,
            'dashboard_section' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
            'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'force' => false,
            'project_ids' => [],
        ]);
        $attributes = [
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
            'stat_date' => null,
            'project_id' => null,
            'ownership_type' => null,
        ];

        HistoricalRecalculationTask::query()->create($attributes);

        $this->expectException(QueryException::class);
        HistoricalRecalculationTask::query()->create($attributes);
    }

    public function test_admin_can_open_historical_recalculation_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        Project::query()->create(['name' => 'Historical Operational Project', 'active' => true]);
        Project::query()->create(['name' => 'Layihəsiz', 'active' => true]);
        Project::query()->create(['name' => 'Təmir', 'active' => true]);
        $this->actingAs($admin)
            ->get(route('admin.historical-recalculations.index'))
            ->assertOk()
            ->assertSee('Tarixi məlumatların yenilənməsi')
            ->assertSee('Geofence Pozuntuları')
            ->assertSee('Wialon report:')
            ->assertSee('Qrup date report Engine hours (api)')
            ->assertSee('monthly_efficiency')
            ->assertViewHas('projects', function ($projects): bool {
                $names = collect($projects)->pluck('name')->all();

                return in_array('Historical Operational Project', $names, true)
                    && ! in_array('Layihəsiz', $names, true)
                    && ! in_array('Təmir', $names, true);
            });
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

    public function test_preview_accepts_single_project_id_from_simple_project_select(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Single select project', 'active' => true]);

        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '102',
            'name' => 'Single select project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '1002');

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-03',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'project_id' => $project->id,
                'force' => true,
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

    public function test_preview_includes_read_only_dry_run_impact_plan(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Dry run project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '1100',
            'name' => 'Dry run project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $equipment = $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '1100');

        DB::table('equipment_daily_stats')->insert([
            'stat_date' => '2026-07-14',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 8.5,
            'distance_km' => 12,
            'utilization_percent' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-13',
                'date_to' => '2026-07-15',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
                'operation' => 'fetch_and_recalculate',
                'scope' => 'selected_projects',
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('dry_run.dashboard_code', 'daily_averages')
            ->assertJsonPath('dry_run.read_only', true)
            ->assertJsonPath('dry_run.writes_shared_tables', true)
            ->assertJsonPath('dry_run.tables.0.table', 'equipment_daily_stats')
            ->assertJsonPath('dry_run.tables.0.existing_rows', 1)
            ->assertJsonPath('dry_run.existing_rows_in_scope', 1);
    }

    public function test_monthly_efficiency_dry_run_counts_isolated_object_facts(): void
    {
        $project = Project::query()->create(['name' => 'Monthly dry run project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '1200',
            'name' => 'Monthly dry run project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $equipment = $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '1200');

        DB::table('monthly_efficiency_unit_geofence_facts')->insert([
            'stat_date' => '2026-07-20',
            'equipment_id' => $equipment->id,
            'wialon_unit_id' => '1200',
            'unit_name' => 'Unit 1200',
            'registration_number' => '10-AA-120',
            'vehicle_type' => 'Excavator',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'segment_type' => 'geofence',
            'geofence_name' => 'Test geofence',
            'engine_hours_decimal' => 7.5,
            'engine_seconds' => 27000,
            'mileage_km' => 4.2,
            'source_report_name' => 'Report for Aylıq effektivlik',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(DashboardResyncDryRunPlanner::class)->plan([
            'dashboard_code' => 'monthly_efficiency',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'project_ids' => [],
            'force' => true,
        ]);

        $this->assertSame('monthly_efficiency', $plan['dashboard_code']);
        $this->assertSame('partially_isolated', $plan['isolation']);
        $this->assertFalse($plan['writes_shared_tables']);
        $this->assertSame(1, $plan['tables'][0]['existing_rows']);
        $this->assertStringContainsString('Force mode may replace existing rows', implode(' ', $plan['warnings']));
    }

    public function test_admin_can_request_dashboard_code_dry_run_without_queueing_run(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.dashboard-resync.dry-run'), [
                'dashboard_code' => 'monthly_efficiency',
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'timezone' => 'Asia/Baku',
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('dashboard_code', 'monthly_efficiency')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('isolation', 'partially_isolated')
            ->assertJsonPath('writes_shared_tables', false);

        $this->assertDatabaseCount('historical_recalculations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_preview_for_monthly_efficiency_queues_one_object_sync_task_per_day(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'force' => true,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 31,
                'project_groups' => 1,
                'fetch_tasks' => 31,
                'aggregate_tasks' => 0,
                'total_tasks' => 31,
            ])
            ->assertJsonPath('dry_run.dashboard_code', 'monthly_efficiency')
            ->assertJsonPath('dry_run.read_only', true)
            ->assertJsonPath('dry_run.writes_shared_tables', false)
            ->assertJsonPath('dry_run.tables.0.table', 'monthly_efficiency_unit_geofence_facts');
    }

    public function test_monthly_efficiency_history_creates_daily_fetch_tasks(): void
    {
        Queue::fake();
        config()->set('historical_recalculation.module_queues.monthly_efficiency', 'historical-monthly-efficiency');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $run = app(HistoricalRecalculationService::class)->createRun([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'force' => true,
        ], $admin);

        $this->assertSame(HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY, $run->dashboard_section);
        $this->assertSame(31, $run->total_tasks);
        $this->assertSame(31, $run->tasks()->count());

        $task = $run->tasks()->orderBy('stat_date')->firstOrFail();
        $this->assertSame(HistoricalRecalculation::OPERATION_FETCH, $task->operation);
        $this->assertSame('2026-07-01', $task->stat_date->toDateString());
        $this->assertNull($task->project_id);
        $this->assertNull($task->ownership_type);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, function ($job): bool {
            return $job->connection === 'database'
                && $job->queue === 'historical-monthly-efficiency';
        });
        Queue::assertNotPushed(FinalizeHistoricalRecalculationJob::class);
    }

    public function test_pipeline_duplicate_validation_uses_only_selected_module_tables(): void
    {
        $project = Project::query()->create(['name' => 'Duplicate validation project', 'active' => true]);

        foreach (['Report A', 'Report B'] as $source) {
            DB::table('efficiency_daily_facts')->insert([
                'business_date' => '2026-08-01',
                'project_id' => $project->id,
                'wialon_group_id' => 'group-1',
                'wialon_unit_id' => 'unit-1',
                'unit_name' => '77-AA-001',
                'vehicle_type' => 'Dump Truck',
                'ownership' => Equipment::OWNERSHIP_NWC,
                'engine_hours_decimal' => 5,
                'engine_seconds' => 18000,
                'mileage_km' => 10,
                'efficiency_status' => 'normal',
                'source_report_template_id' => 1,
                'source_report_name' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $service = app(DashboardReportPipelineService::class);
        $method = new \ReflectionMethod($service, 'duplicateFactChecks');
        $method->setAccessible(true);

        $monthlyChecks = $method->invoke($service, [
            'plans' => [[
                'section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-01',
            ]],
        ]);

        $this->assertArrayNotHasKey('efficiency_daily_facts', $monthlyChecks);
        $this->assertSame(0, $monthlyChecks['monthly_efficiency_unit_geofence_facts'] ?? null);

        $efficiencyChecks = $method->invoke($service, [
            'plans' => [[
                'section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-01',
            ]],
        ]);

        $this->assertSame(0, $efficiencyChecks['efficiency_daily_facts'] ?? null);
    }

    public function test_monthly_efficiency_allows_selected_projects_scope(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Monthly selected project', 'active' => true]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '231',
            'name' => 'Monthly selected project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 31,
                'project_groups' => 1,
                'fetch_tasks' => 31,
                'aggregate_tasks' => 0,
                'total_tasks' => 31,
            ]);

        $run = app(HistoricalRecalculationService::class)->createRun([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => true,
        ], $admin);

        $this->assertSame(31, $run->tasks()->count());
        $this->assertTrue($run->tasks()->get()->every(
            fn (HistoricalRecalculationTask $task): bool => (int) $task->project_id === (int) $project->id
        ));
    }

    public function test_monthly_efficiency_history_uses_project_scoped_command(): void
    {
        $project = Project::query()->create(['name' => 'Monthly command project', 'active' => true]);
        $otherProject = Project::query()->create(['name' => 'Other monthly command project', 'active' => true]);
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '7194341d-532c-4f14-991a-ff07a64a5c6c',
            'signature' => 'monthly-selected-command-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => true,
            'project_ids' => [$project->id],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_RUNNING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
            'project_id' => $project->id,
        ]);
        DB::table('monthly_efficiency_unit_geofence_facts')->insert([
            [
                'stat_date' => '2026-07-29',
                'project_id' => $project->id,
                'wialon_unit_id' => 'monthly-1',
                'unit_name' => 'Monthly 1',
                'vehicle_type' => 'Excavator',
                'segment_type' => 'total',
                'geofence_name' => 'Total',
                'engine_hours_decimal' => 1,
                'engine_seconds' => 3600,
                'mileage_km' => 1,
                'visits_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'stat_date' => '2026-07-29',
                'project_id' => $otherProject->id,
                'wialon_unit_id' => 'monthly-2',
                'unit_name' => 'Monthly 2',
                'vehicle_type' => 'Excavator',
                'segment_type' => 'total',
                'geofence_name' => 'Total',
                'engine_hours_decimal' => 1,
                'engine_seconds' => 3600,
                'mileage_km' => 1,
                'visits_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('monthly-efficiency:sync-objects', \Mockery::on(
                fn (array $parameters): bool => $parameters['--project'] === $project->id
                    && $parameters['--from'] === '2026-07-29'
                    && $parameters['--to'] === '2026-07-29'
                    && $parameters['--force'] === true
            ))
            ->andReturn(0);

        $count = app(HistoricalRecalculationModuleRegistry::class)->execute($run, $task);

        $this->assertSame(1, $count);
    }

    public function test_selected_project_monthly_task_cannot_execute_without_project_scope(): void
    {
        $project = Project::query()->create(['name' => 'Monthly scope guard project', 'active' => true]);
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '0ec58fd8-095a-4d33-a9e9-33e86f7424b1',
            'signature' => 'monthly-selected-scope-guard-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => true,
            'project_ids' => [$project->id],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_RUNNING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
            'project_id' => null,
        ]);

        Artisan::shouldReceive('call')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Selected-project historical task {$task->id} has an invalid project scope.");

        app(HistoricalRecalculationModuleRegistry::class)->execute($run, $task);
    }

    public function test_preview_rejects_removed_top20_section(): void
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dashboard_section');
    }

    public function test_preview_for_efficiency_section_uses_one_task_per_project_and_date(): void
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
                'project_groups' => 1,
                'fetch_tasks' => 3,
                'aggregate_tasks' => 0,
                'total_tasks' => 3,
            ]);
    }

    public function test_checked_force_option_is_stored_for_efficiency_recalculation(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Forced efficiency project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '252',
            'name' => 'Forced efficiency project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '2520');

        $this->actingAs($admin)
            ->post(route('admin.historical-recalculations.store'), [
                'date_from' => '2026-07-31',
                'date_to' => '2026-07-31',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('historical_recalculations', [
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
            'force' => true,
            'requested_by' => $admin->id,
        ]);
        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);
        $this->assertSame('manual', $pipelines[0]['source']);
        $this->assertSame($admin->id, $pipelines[0]['requested_by']);
        $this->assertSame(HistoricalRecalculation::OPERATION_RECALCULATE, $pipelines[0]['plans'][0]['operation']);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class);
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

    public function test_geofence_preview_excludes_layihesiz_groups_from_all_projects(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $excludedProject = Project::query()->create(['name' => 'Layihəsiz', 'active' => true]);
        $allowedProject = Project::query()->create(['name' => 'Allowed geofence project', 'active' => true]);

        foreach ([
            [$excludedProject->id, '601705305', 'Layihəsiz - NWC'],
            [$excludedProject->id, '601708440', 'Layihəsiz - İcarə'],
            [$allowedProject->id, '601700001', 'Allowed geofence project - NWC'],
        ] as [$projectId, $groupId, $name]) {
            ProjectWialonGroup::query()->create([
                'project_id' => $projectId,
                'wialon_group_id' => $groupId,
                'name' => $name,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'is_active' => true,
            ]);
        }

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-27',
                'date_to' => '2026-07-28',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'force' => true,
            ])
            ->assertOk()
            ->assertJson([
                'days' => 2,
                'project_groups' => 1,
                'fetch_tasks' => 2,
                'aggregate_tasks' => 0,
                'total_tasks' => 2,
            ]);
    }

    public function test_preview_for_all_dashboards_expands_to_daily_module_pipeline_steps(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'All dashboards preview', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '801',
            'name' => 'All dashboards preview - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '80101');

        $response = $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_ALL_DASHBOARDS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('mode', HistoricalRecalculation::SECTION_ALL_DASHBOARDS)
            ->assertJsonPath('days', 2)
            ->assertJsonPath('pipeline_steps', 10);

        $modules = collect($response->json('modules'));

        $this->assertSame(
            [
                HistoricalRecalculation::SECTION_DAILY_AVERAGES,
                HistoricalRecalculation::SECTION_EFFICIENCY,
                HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
                HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
                HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            ],
            $modules->take(5)->pluck('section')->all()
        );
        $this->assertTrue($modules->take(5)->every(fn (array $module): bool => $module['date_from'] === '2026-07-29'));
        $this->assertTrue($modules->slice(5, 5)->every(fn (array $module): bool => $module['date_from'] === '2026-07-30'));
    }

    public function test_store_all_dashboards_queues_one_manual_pipeline_with_per_day_module_steps(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'All dashboards queue', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '802',
            'name' => 'All dashboards queue - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '80201');

        $this->actingAs($admin)
            ->from(route('admin.historical-recalculations.index'))
            ->post(route('admin.historical-recalculations.store'), [
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-29',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_ALL_DASHBOARDS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'force' => true,
            ])
            ->assertRedirect();

        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);

        $this->assertCount(1, $pipelines);
        $this->assertSame('manual', $pipelines[0]['source']);
        $this->assertCount(5, $pipelines[0]['plans']);
        $this->assertSame(HistoricalRecalculation::SECTION_DAILY_AVERAGES, $pipelines[0]['plans'][0]['section']);
        $this->assertSame(HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE, $pipelines[0]['plans'][4]['section']);
        $this->assertTrue(collect($pipelines[0]['plans'])->every(
            fn (array $plan): bool => $plan['date_from'] === '2026-07-29'
                && $plan['date_to'] === '2026-07-29'
                && $plan['operation'] === HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE
                && $plan['force'] === true
        ));

        $this->assertDatabaseCount('historical_recalculations', 1);
        $this->assertDatabaseHas('historical_recalculations', [
            'dashboard_section' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
            'date_from' => '2026-07-29 00:00:00',
            'date_to' => '2026-07-29 00:00:00',
            'force' => 1,
        ]);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);
    }

    public function test_all_dashboards_allows_selected_projects_scope(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Selected all dashboard project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '803',
            'name' => 'Selected all dashboard project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '80301');

        $this->actingAs($admin)
            ->from(route('admin.historical-recalculations.index'))
            ->post(route('admin.historical-recalculations.store'), [
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-29',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_ALL_DASHBOARDS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);

        $this->assertCount(1, $pipelines);
        $this->assertCount(5, $pipelines[0]['plans']);
        $this->assertTrue(collect($pipelines[0]['plans'])->every(
            fn (array $plan): bool => $plan['scope'] === HistoricalRecalculation::SCOPE_SELECTED_PROJECTS
                && $plan['project_ids'] === [$project->id]
        ));
        $this->assertDatabaseHas('historical_recalculations', [
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
        ]);
    }

    public function test_selected_project_all_dashboards_can_queue_a_full_month_pipeline(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Selected full month project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '804',
            'name' => 'Selected full month project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '80401');

        $this->actingAs($admin)
            ->from(route('admin.historical-recalculations.index'))
            ->post(route('admin.historical-recalculations.store'), [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_ALL_DASHBOARDS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $stored = (string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value');
        $pipelines = json_decode($stored, true);

        $this->assertGreaterThan(30000, strlen($stored));
        $this->assertCount(1, $pipelines);
        $this->assertCount(31 * 5, $pipelines[0]['plans']);
        $this->assertTrue(collect($pipelines[0]['plans'])->every(
            fn (array $plan): bool => $plan['scope'] === HistoricalRecalculation::SCOPE_SELECTED_PROJECTS
                && $plan['project_ids'] === [$project->id]
                && $plan['force'] === true
        ));
        $this->assertDatabaseCount('historical_recalculations', 1);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);
    }

    public function test_selected_layihesiz_project_is_rejected_for_geofence_modules(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Layihəsiz', 'active' => true]);

        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601705305',
            'name' => 'Layihəsiz - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.historical-recalculations.preview'), [
                'date_from' => '2026-07-27',
                'date_to' => '2026-07-27',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_ids'])
            ->assertJsonPath('errors.project_ids.0', GeofenceExcludedGroups::MESSAGE);
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
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => true,
        ], $admin);
        $task = $run->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->firstOrFail();

        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Wialon report failed.');

        (new RunHistoricalRecalculationTaskJob($task->id))->handle(
            app(HistoricalRecalculationModuleRegistry::class),
            $service
        );

        $this->assertSame(HistoricalRecalculationTask::STATUS_FAILED, $task->refresh()->status);
        $this->assertStringContainsString('exit code 1', (string) $task->error_message);
    }

    public function test_temporary_wialon_error_is_retried_before_task_is_failed(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Temporary command project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '501',
            'name' => 'Temporary command project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '5010');
        $service = app(HistoricalRecalculationService::class);
        $run = $service->createRun([
            'date_from' => '2026-07-28',
            'date_to' => '2026-07-28',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => true,
        ], $admin);
        $task = $run->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->firstOrFail();

        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Wialon API error 1004: temporary report execution limit.');

        $job = (new RunHistoricalRecalculationTaskJob($task->id))->withFakeQueueInteractions();
        $job->handle(
            app(HistoricalRecalculationModuleRegistry::class),
            $service
        );
        $job->assertReleased(60);

        $this->assertSame(HistoricalRecalculationTask::STATUS_PENDING, $task->refresh()->status);
        $this->assertSame(1, (int) $task->attempts);
        $this->assertStringContainsString('Temporary failure', (string) $task->error_message);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);
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
            app(HistoricalRecalculationModuleRegistry::class),
            $service
        );

        $this->assertSame(HistoricalRecalculationTask::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_efficiency_history_uses_only_the_new_handler_and_tables(): void
    {
        $definition = app(HistoricalRecalculationModuleRegistry::class)
            ->definition(HistoricalRecalculation::SECTION_EFFICIENCY);

        $this->assertSame(EfficiencyRecalculationHandler::class, $definition['service']);
        $this->assertSame([], $definition['aliases']);
        $this->assertSame([
            'efficiency_daily_facts',
            'efficiency_sync_runs',
            'efficiency_sync_tasks',
            'equipment_daily_stats',
            'daily_unit_aggregates',
            'engine_hours_report_unit_days',
            'wialon_report_sync_items',
        ], $definition['result_tables']);
    }

    public function test_historical_jobs_define_explicit_retry_and_timeout_policies(): void
    {
        $taskJob = new RunHistoricalRecalculationTaskJob(1);
        $finalizeJob = new FinalizeHistoricalRecalculationJob(1);

        $this->assertSame(8, $taskJob->tries);
        $this->assertSame(900, $taskJob->timeout);
        $this->assertTrue($taskJob->failOnTimeout);
        $this->assertSame([60, 180, 300, 600, 900, 1800, 3600], $taskJob->backoff());
        $this->assertSame(3, $finalizeJob->tries);
        $this->assertSame(300, $finalizeJob->timeout);
        $this->assertTrue($finalizeJob->failOnTimeout);
    }

    public function test_registry_covers_every_ui_module_with_canonical_efficiency_code(): void
    {
        $registry = app(HistoricalRecalculationModuleRegistry::class);

        $this->assertSame([
            HistoricalRecalculation::SECTION_DAILY_AVERAGES,
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
        ], array_keys($registry->definitions()));
        foreach ($registry->definitions() as $definition) {
            $this->assertSame('historical-recalculations', $definition['queue']);
            $this->assertSame(RunHistoricalRecalculationTaskJob::class, $definition['job']);
            $this->assertNotEmpty($definition['result_tables']);
        }

        $view = file_get_contents(resource_path('views/admin/historical-recalculations/index.blade.php'));
        foreach (array_keys($registry->definitions()) as $moduleCode) {
            $this->assertStringContainsString('value="'.$moduleCode.'"', $view);
        }
        $this->assertStringNotContainsString('value="daytime_efficiency"', $view);
        $this->assertStringNotContainsString('value="night_day_efficiency"', $view);
    }

    public function test_monthly_efficiency_can_use_dedicated_historical_queue(): void
    {
        Queue::fake();
        config()->set('historical_recalculation.module_queues.monthly_efficiency', 'historical-monthly-efficiency');

        $run = HistoricalRecalculation::query()->create([
            'uuid' => 'd8e51db3-e084-4747-94c9-c9c8b2ef0fa0',
            'signature' => 'monthly-dedicated-queue-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);

        HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_COMPLETED,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
        ]);

        app(HistoricalRecalculationService::class)->dispatchNextPendingFetchTask($run);

        Queue::assertPushed(FinalizeHistoricalRecalculationJob::class, function ($job): bool {
            return $job->connection === 'database'
                && $job->queue === 'historical-monthly-efficiency';
        });
    }

    public function test_historical_page_exposes_pipeline_queue_snapshot(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        Setting::query()->create([
            'key' => 'dashboard_report_pipelines',
            'value' => json_encode([[
                'id' => 'pipeline-visible-test',
                'signature' => 'visible-signature',
                'source' => 'manual',
                'priority' => 50,
                'status' => 'pending',
                'plans' => [[
                    'section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                    'date_from' => '2026-08-01',
                    'date_to' => '2026-08-01',
                    'timezone' => 'Asia/Baku',
                    'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                    'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                    'project_ids' => [],
                    'force' => true,
                ]],
                'current_index' => 0,
                'current_run_id' => null,
                'run_ids' => [],
                'steps' => [],
                'errors' => [],
                'created_at' => '2026-08-02 12:00:00',
                'updated_at' => '2026-08-02 12:00:00',
            ]], JSON_UNESCAPED_SLASHES),
            'is_secret' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.historical-recalculations.index'))
            ->assertOk()
            ->assertSee('Pipeline növbəsi')
            ->assertSee('Queue ID')
            ->assertSee('Effektivlik')
            ->assertSee(route('admin.historical-recalculations.pipeline.clear-closed'), false);
    }

    public function test_admin_can_clear_only_closed_pipeline_entries(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $closedRun = HistoricalRecalculation::query()->create([
            'uuid' => '61111ba4-cc65-4ade-af1a-88f522df9ce9',
            'signature' => 'closed-run',
            'status' => HistoricalRecalculation::STATUS_COMPLETED,
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        Setting::query()->create([
            'key' => 'dashboard_report_pipelines',
            'value' => json_encode([
                [
                    'id' => 'active-pipeline',
                    'signature' => 'active-signature',
                    'source' => 'daily',
                    'priority' => 100,
                    'status' => 'pending',
                    'plans' => [[
                        'section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                        'date_from' => '2026-08-01',
                        'date_to' => '2026-08-01',
                        'timezone' => 'Asia/Baku',
                        'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                        'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                        'project_ids' => [],
                        'force' => true,
                    ]],
                    'current_index' => 0,
                    'current_run_id' => null,
                    'run_ids' => [],
                    'steps' => [],
                    'errors' => [],
                    'created_at' => '2026-08-02 12:00:00',
                    'updated_at' => '2026-08-02 12:00:00',
                ],
                [
                    'id' => 'closed-pipeline',
                    'signature' => 'closed-signature',
                    'source' => 'manual',
                    'priority' => 50,
                    'status' => 'completed',
                    'plans' => [[
                        'section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                        'date_from' => '2026-08-01',
                        'date_to' => '2026-08-01',
                        'timezone' => 'Asia/Baku',
                        'operation' => HistoricalRecalculation::OPERATION_FETCH,
                        'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                        'project_ids' => [],
                        'force' => false,
                    ]],
                    'current_index' => 1,
                    'current_run_id' => null,
                    'run_ids' => [$closedRun->id],
                    'steps' => [],
                    'errors' => [],
                    'created_at' => '2026-08-02 11:00:00',
                    'updated_at' => '2026-08-02 11:30:00',
                    'completed_at' => '2026-08-02 11:30:00',
                ],
            ], JSON_UNESCAPED_SLASHES),
            'is_secret' => false,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.historical-recalculations.index'))
            ->post(route('admin.historical-recalculations.pipeline.clear-closed'))
            ->assertRedirect(route('admin.historical-recalculations.index'))
            ->assertSessionHas('status');

        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);

        $this->assertCount(1, $pipelines);
        $this->assertSame('active-pipeline', $pipelines[0]['id']);
        $this->assertDatabaseHas('historical_recalculations', ['id' => $closedRun->id]);
    }

    public function test_dashboard_reports_daily_queue_uses_completed_baku_periods(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-02 00:00:00', 'Asia/Baku'));

        try {
            $project = Project::query()->create(['name' => 'Daily dashboard sync', 'active' => true]);
            ProjectWialonGroup::query()->create([
                'project_id' => $project->id,
                'wialon_group_id' => '710',
                'name' => 'Daily dashboard sync - NWC',
                'ownership_type' => Equipment::OWNERSHIP_NWC,
            ]);

            $this->artisan('dashboard-reports:queue-sync', [
                '--daily' => true,
                '--module' => [
                    HistoricalRecalculation::SECTION_EFFICIENCY,
                    HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                ],
                '--force' => true,
            ])->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $firstRun = HistoricalRecalculation::query()
            ->where('dashboard_section', HistoricalRecalculation::SECTION_EFFICIENCY)
            ->firstOrFail();

        $this->assertDatabaseCount('historical_recalculations', 1);
        $this->assertDatabaseHas('historical_recalculations', [
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'date_from' => '2026-08-01 00:00:00',
            'date_to' => '2026-08-01 00:00:00',
            'force' => 1,
        ]);

        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);
        $this->assertCount(1, $pipelines);
        $this->assertSame('daily', $pipelines[0]['source']);
        $this->assertSame(100, $pipelines[0]['priority']);
        $this->assertSame($firstRun->id, $pipelines[0]['current_run_id']);
        $this->assertCount(2, $pipelines[0]['plans']);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);

        $firstRun->tasks()->update([
            'status' => HistoricalRecalculationTask::STATUS_COMPLETED,
            'completed_at' => now('Asia/Baku'),
        ]);
        $firstRun->forceFill([
            'status' => HistoricalRecalculation::STATUS_COMPLETED,
            'completed_at' => now('Asia/Baku'),
        ])->save();

        app(DashboardReportPipelineService::class)->handleRunFinished($firstRun->refresh());

        $this->assertDatabaseHas('historical_recalculations', [
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'date_from' => '2026-08-01 00:00:00',
            'date_to' => '2026-08-01 00:00:00',
            'force' => 1,
        ]);
        $this->assertDatabaseCount('historical_recalculations', 2);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 2);
    }

    public function test_sync_daily_command_queues_five_step_master_pipeline_without_cross_midnight_nighttime(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-02 00:00:00', 'Asia/Baku'));

        try {
            $project = Project::query()->create(['name' => 'Daily sync command', 'active' => true]);
            $group = ProjectWialonGroup::query()->create([
                'project_id' => $project->id,
                'wialon_group_id' => '711',
                'name' => 'Daily sync command - NWC',
                'ownership_type' => Equipment::OWNERSHIP_NWC,
            ]);
            $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '71101');

            $this->artisan('dashboard-reports:sync-daily')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);

        $this->assertCount(1, $pipelines);
        $this->assertSame('daily', $pipelines[0]['source']);
        $this->assertSame([
            HistoricalRecalculation::SECTION_EFFICIENCY,
            HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
            HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
        ], collect($pipelines[0]['plans'])->pluck('section')->all());
        $this->assertTrue(collect($pipelines[0]['plans'])->every(fn (array $plan): bool => (bool) $plan['force']));
        $this->assertDatabaseCount('historical_recalculations', 1);
        $this->assertDatabaseHas('historical_recalculations', [
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'date_from' => '2026-08-01 00:00:00',
            'date_to' => '2026-08-01 00:00:00',
        ]);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);
    }

    public function test_dashboard_reports_historical_queue_splits_ranges_into_weekly_pipeline_steps(): void
    {
        Queue::fake();
        $project = Project::query()->create(['name' => 'Chunked dashboard sync', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '720',
            'name' => 'Chunked dashboard sync - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '72001');

        $this->artisan('dashboard-reports:queue-sync', [
            '--from' => '2026-06-01',
            '--to' => '2026-06-15',
            '--module' => [HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE],
            '--chunk-days' => 7,
            '--force' => true,
        ])->assertSuccessful();

        $pipelines = json_decode((string) Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);

        $this->assertCount(1, $pipelines);
        $this->assertSame('historical', $pipelines[0]['source']);
        $this->assertSame(10, $pipelines[0]['priority']);
        $this->assertSame([
            ['from' => '2026-06-01', 'to' => '2026-06-07'],
            ['from' => '2026-06-08', 'to' => '2026-06-14'],
            ['from' => '2026-06-15', 'to' => '2026-06-15'],
        ], collect($pipelines[0]['plans'])->map(fn (array $plan): array => [
            'from' => $plan['date_from'],
            'to' => $plan['date_to'],
        ])->all());
        $this->assertDatabaseCount('historical_recalculations', 1);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, 1);
    }

    public function test_store_rejects_run_when_selected_project_has_no_executable_tasks(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Empty project', 'active' => true]);

        $this->actingAs($admin)
            ->from(route('admin.historical-recalculations.index'))
            ->post(route('admin.historical-recalculations.store'), [
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-29',
                'timezone' => 'Asia/Baku',
                'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
                'project_ids' => [$project->id],
                'force' => false,
            ])
            ->assertRedirect(route('admin.historical-recalculations.index'))
            ->assertSessionHasErrors('project_ids');

        $this->assertDatabaseCount('historical_recalculations', 0);
        $this->assertDatabaseCount('historical_recalculation_tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_historical_dispatch_uses_database_connection_and_expected_queue(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Database queue project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '610',
            'name' => 'Database queue project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '6100');

        app(HistoricalRecalculationService::class)->createRun([
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => [$project->id],
            'force' => false,
        ], $admin);

        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, function ($job): bool {
            return $job->connection === 'database' && $job->queue === 'historical-recalculations';
        });
    }

    public function test_last_terminal_task_dispatches_finalize_job(): void
    {
        Queue::fake();
        $run = HistoricalRecalculation::query()->create([
            'uuid' => 'dd9b94d7-5ed2-43ae-81b8-af400017f875',
            'signature' => 'finalize-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_COMPLETED,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
        ]);

        app(HistoricalRecalculationService::class)->dispatchNextPendingFetchTask($run);

        Queue::assertPushed(FinalizeHistoricalRecalculationJob::class, function ($job): bool {
            return $job->connection === 'database' && $job->queue === 'historical-recalculations';
        });
    }

    public function test_diagnose_runs_is_read_only_until_explicit_repair(): void
    {
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '1ed342ca-2f62-4a92-b178-894a59c02f84',
            'signature' => 'diagnose-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
            'total_tasks' => 1,
            'completed_tasks' => 1,
        ]);
        HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_COMPLETED,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
        ]);

        $this->artisan('historical:diagnose-runs', ['--run' => [$run->id]])
            ->expectsOutputToContain('FINALIZE')
            ->assertSuccessful();
        $this->assertSame(HistoricalRecalculation::STATUS_RUNNING, $run->refresh()->status);

        $this->artisan('historical:diagnose-runs', [
            '--run' => [$run->id],
            '--repair' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(HistoricalRecalculation::STATUS_COMPLETED, $run->refresh()->status);
    }

    public function test_worker_level_failure_marks_task_failed_and_continues_chain(): void
    {
        Queue::fake();
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '244fcdd6-56ae-4f10-976a-b9a625612ce3',
            'signature' => 'worker-failure-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-30',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        $first = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_RUNNING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
        ]);
        $second = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-30',
        ]);

        (new RunHistoricalRecalculationTaskJob($first->id))->failed(new \RuntimeException('Worker timeout'));

        $this->assertSame(HistoricalRecalculationTask::STATUS_FAILED, $first->refresh()->status);
        $this->assertStringContainsString('Worker timeout', (string) $first->error_message);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, fn ($job): bool => $job->taskId === $second->id);
    }

    public function test_stale_running_fetch_task_is_failed_and_chain_continues(): void
    {
        Queue::fake();
        config()->set('historical_recalculation.stale_running_task_seconds', 60);
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Asia/Baku'));

        try {
            $run = HistoricalRecalculation::query()->create([
                'uuid' => 'c93c9658-46df-4f53-93cb-7d6ecf8b2e3a',
                'signature' => 'stale-running-test',
                'status' => HistoricalRecalculation::STATUS_RUNNING,
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
                'timezone' => 'Asia/Baku',
                'force' => false,
                'project_ids' => [],
            ]);
            $first = HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_RUNNING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-29',
                'attempts' => 1,
                'last_heartbeat_at' => now(config('app.timezone'))->subMinutes(5),
            ]);
            $second = HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_PENDING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-30',
            ]);
            $runLock = Cache::lock('historical-recalculation-run-execution:'.$run->id, 7200);
            $taskLock = Cache::lock('historical-recalculation-task:'.$first->id, 7200);
            $this->assertTrue($runLock->get());
            $this->assertTrue($taskLock->get());

            app(HistoricalRecalculationService::class)->dispatchNextPendingFetchTask($run);

            $releasedRunLock = Cache::lock('historical-recalculation-run-execution:'.$run->id, 7200);
            $releasedTaskLock = Cache::lock('historical-recalculation-task:'.$first->id, 7200);
            $this->assertTrue($releasedRunLock->get());
            $this->assertTrue($releasedTaskLock->get());
            $releasedRunLock->release();
            $releasedTaskLock->release();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(HistoricalRecalculationTask::STATUS_FAILED, $first->refresh()->status);
        $this->assertStringContainsString('Stale running task recovered', (string) $first->error_message);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, fn ($job): bool => $job->taskId === $second->id);
    }

    public function test_fresh_running_fetch_task_blocks_next_dispatch(): void
    {
        Queue::fake();
        config()->set('historical_recalculation.stale_running_task_seconds', 600);
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Asia/Baku'));

        try {
            $run = HistoricalRecalculation::query()->create([
                'uuid' => '6417fc1c-47b6-4e09-a060-872a3d0b631e',
                'signature' => 'fresh-running-test',
                'status' => HistoricalRecalculation::STATUS_RUNNING,
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
                'timezone' => 'Asia/Baku',
                'force' => false,
                'project_ids' => [],
            ]);
            $first = HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_RUNNING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-29',
                'attempts' => 1,
                'last_heartbeat_at' => now(config('app.timezone')),
            ]);
            HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_PENDING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-30',
            ]);

            app(HistoricalRecalculationService::class)->dispatchNextPendingFetchTask($run);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(HistoricalRecalculationTask::STATUS_RUNNING, $first->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_of_fresh_running_task_does_not_fail_or_requeue_duplicate_job(): void
    {
        Queue::fake();
        config()->set('historical_recalculation.stale_running_task_seconds', 600);
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Asia/Baku'));

        try {
            $run = HistoricalRecalculation::query()->create([
                'uuid' => 'd1b41070-cf1c-4605-b121-36448b68fb44',
                'signature' => 'fresh-running-job-test',
                'status' => HistoricalRecalculation::STATUS_RUNNING,
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
                'timezone' => 'Asia/Baku',
                'force' => false,
                'project_ids' => [],
            ]);
            $task = HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_RUNNING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-29',
                'attempts' => 1,
                'last_heartbeat_at' => now(config('app.timezone'))->subSeconds(120),
            ]);
            HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_PENDING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-30',
            ]);

            $job = (new RunHistoricalRecalculationTaskJob($task->id))->withFakeQueueInteractions();
            $job->handle(app(HistoricalRecalculationModuleRegistry::class), app(HistoricalRecalculationService::class));
            $job->assertNotReleased();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(HistoricalRecalculationTask::STATUS_RUNNING, $task->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_legacy_run_execution_lock_does_not_block_pending_task(): void
    {
        Queue::fake();
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '3a1552ed-9f80-42da-b44d-44c459ce3a87',
            'signature' => 'legacy-run-lock-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
        ]);
        $runLock = Cache::lock('historical-recalculation-run-execution:'.$run->id, 7200);
        $this->assertTrue($runLock->get());

        try {
            Artisan::shouldReceive('call')->once()->andReturn(0);

            (new RunHistoricalRecalculationTaskJob($task->id))->handle(
                app(HistoricalRecalculationModuleRegistry::class),
                app(HistoricalRecalculationService::class)
            );
        } finally {
            $runLock->release();
        }

        $this->assertSame(HistoricalRecalculationTask::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_legacy_task_lock_does_not_block_pending_task_claim(): void
    {
        Queue::fake();
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '76705dbb-57c3-4085-9db7-a95eebc40a85',
            'signature' => 'legacy-task-lock-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-29',
        ]);
        $taskLock = Cache::lock('historical-recalculation-task:'.$task->id, 7200);
        $this->assertTrue($taskLock->get());

        try {
            Artisan::shouldReceive('call')->once()->andReturn(0);

            (new RunHistoricalRecalculationTaskJob($task->id))->handle(
                app(HistoricalRecalculationModuleRegistry::class),
                app(HistoricalRecalculationService::class)
            );
        } finally {
            $taskLock->release();
        }

        $this->assertSame(HistoricalRecalculationTask::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_diagnose_runs_can_recover_stale_running_task_for_explicit_run(): void
    {
        Queue::fake();
        config()->set('historical_recalculation.stale_running_task_seconds', 60);
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Asia/Baku'));

        try {
            $run = HistoricalRecalculation::query()->create([
                'uuid' => 'cfce5fd0-7baa-47f2-aa14-9c104623a48d',
                'signature' => 'diagnose-stale-running-test',
                'status' => HistoricalRecalculation::STATUS_RUNNING,
                'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
                'timezone' => 'Asia/Baku',
                'force' => false,
                'project_ids' => [],
            ]);
            $first = HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_RUNNING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-29',
                'attempts' => 1,
                'last_heartbeat_at' => now(config('app.timezone'))->subMinutes(5),
            ]);
            $second = HistoricalRecalculationTask::query()->create([
                'historical_recalculation_id' => $run->id,
                'status' => HistoricalRecalculationTask::STATUS_PENDING,
                'operation' => HistoricalRecalculation::OPERATION_FETCH,
                'stat_date' => '2026-07-30',
            ]);

            $this->artisan('historical:diagnose-runs', [
                '--run' => [$run->id],
                '--recover-stale-running' => true,
                '--force' => true,
            ])->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(HistoricalRecalculationTask::STATUS_FAILED, $first->refresh()->status);
        $this->assertStringContainsString('Stale running task recovered', (string) $first->error_message);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, fn ($job): bool => $job->taskId === $second->id);
    }

    public function test_stuck_queue_cleanup_removes_obsolete_historical_job_and_resumes_active_run(): void
    {
        Queue::fake();

        $cancelledRun = HistoricalRecalculation::query()->create([
            'uuid' => 'f16b7bc2-4d42-4dd7-a8fa-533e8dfc141d',
            'signature' => 'cancelled-cleanup-test',
            'status' => HistoricalRecalculation::STATUS_CANCELLED,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        $cancelledTask = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $cancelledRun->id,
            'status' => HistoricalRecalculationTask::STATUS_CANCELLED,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-08-01',
        ]);
        $activeRun = HistoricalRecalculation::query()->create([
            'uuid' => 'f487e1ae-eba9-4fc8-9671-a96085ad3c06',
            'signature' => 'active-cleanup-test',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        $activeTask = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $activeRun->id,
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-08-01',
        ]);

        $staleJobId = DB::table('jobs')->insertGetId($this->historicalQueueJobRow(
            new RunHistoricalRecalculationTaskJob($cancelledTask->id)
        ));

        $summary = app(HistoricalRecalculationService::class)->cleanupStuckQueue();

        $this->assertSame(1, $summary['deleted_jobs']);
        $this->assertSame(1, $summary['active_runs_resumed']);
        $this->assertDatabaseMissing('jobs', ['id' => $staleJobId]);
        Queue::assertPushed(RunHistoricalRecalculationTaskJob::class, fn ($job): bool => $job->taskId === $activeTask->id);
    }

    public function test_settings_page_exposes_historical_cleanup_action(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);

        $this->actingAs($admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Historical run cleanup')
            ->assertSee(route('settings.cleanup-historical-runs'), false);
    }

    public function test_admin_can_trigger_stuck_queue_cleanup_from_settings(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $run = HistoricalRecalculation::query()->create([
            'uuid' => '77e0d6e6-b7e0-405f-bac2-f34bc7523a47',
            'signature' => 'settings-cleanup-test',
            'status' => HistoricalRecalculation::STATUS_CANCELLED,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_CANCELLED,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-08-01',
        ]);
        $activeRun = HistoricalRecalculation::query()->create([
            'uuid' => 'd7bdf3b0-afb7-45fe-aa38-1f0198ff1525',
            'signature' => 'settings-cleanup-active-target',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'timezone' => 'Asia/Baku',
            'force' => false,
            'project_ids' => [],
        ]);
        HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $activeRun->id,
            'status' => HistoricalRecalculationTask::STATUS_PENDING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-08-01',
        ]);
        $staleJobId = DB::table('jobs')->insertGetId($this->historicalQueueJobRow(
            new RunHistoricalRecalculationTaskJob($task->id)
        ));

        $this->actingAs($admin)
            ->from(route('settings.edit'))
            ->post(route('settings.cleanup-historical-runs'))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('jobs', ['id' => $staleJobId]);
    }

    private function historicalQueueJobRow(object $job): array
    {
        return [
            'queue' => (string) config('historical_recalculation.queue', 'historical-recalculations'),
            'payload' => json_encode([
                'displayName' => $job::class,
                'data' => [
                    'commandName' => $job::class,
                    'command' => serialize($job),
                ],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now(config('app.timezone'))->timestamp,
            'created_at' => now(config('app.timezone'))->timestamp,
        ];
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
