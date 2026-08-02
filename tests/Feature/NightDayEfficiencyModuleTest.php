<?php

namespace Tests\Feature;

use App\Models\DaytimeEfficiencyDailyFact;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\NightDayEfficiencyDailyFact;
use App\Models\NightDayEfficiencySyncRun;
use App\Models\NightDayEfficiencySyncTask;
use App\Models\NighttimeEfficiencyDailyFact;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\HistoricalRecalculationService;
use App\Services\NightDayEfficiencyDashboardService;
use App\Services\NightDayEfficiencyRecalculationHandler;
use App\Services\WialonNightDayEfficiencyReportParser;
use App\Services\WialonNightDayEfficiencyReportService;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use App\Services\WialonSessionManager;
use App\Support\EfficiencyStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NightDayEfficiencyModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_service_resolves_only_the_night_day_template_and_converts_baku_windows_for_api(): void
    {
        config()->set('fleet.wialon.night_day_efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.night_day_efficiency_report_template_id', 22);
        config()->set('fleet.wialon.night_day_efficiency_report_template_name', 'night day report Engine hours (api)');

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')
            ->once()
            ->with(601701680, 'night day report Engine hours (api)')
            ->andReturn(['resource_id' => 601701680, 'id' => 22, 'type' => 'avl_unit_group']);
        $wialon->shouldReceive('getReportTemplateData')->once()->andReturn([
            'tbl' => [[
                'n' => 'unit_group_engine_hours',
                'sch' => ['f1' => 0, 't1' => 479, 'f2' => 1080, 't2' => 1439, 'fl' => 1],
            ]],
        ]);
        $wialon->shouldReceive('cleanupReportResult')->twice();
        $execution = [];
        $wialon->shouldReceive('executeReportTemplate')->once()->andReturnUsing(function (...$arguments) use (&$execution): array {
            $execution = $arguments;

            return ['reportResult' => ['tables' => []]];
        });

        $lock = Mockery::mock(WialonReportSessionLock::class);
        $lock->shouldReceive('run')->once()->andReturnUsing(fn (callable $callback): mixed => $callback());
        $group = new ProjectWialonGroup(['wialon_group_id' => '9001']);
        $date = CarbonImmutable::parse('2026-07-31', 'Asia/Baku');

        $result = (new WialonNightDayEfficiencyReportService($wialon, $lock))
            ->execute($group, $date->startOfDay(), $date->endOfDay(), 'test-session');

        $this->assertSame(22, $result['template_id']);
        $this->assertSame('night day report Engine hours (api)', $result['template_name']);
        $this->assertSame(0, $execution[1]['tbl'][0]['sch']['f1']);
        $this->assertSame(239, $execution[1]['tbl'][0]['sch']['t1']);
        $this->assertSame(840, $execution[1]['tbl'][0]['sch']['f2']);
        $this->assertSame(1439, $execution[1]['tbl'][0]['sch']['t2']);
        $this->assertSame(CarbonImmutable::parse('2026-07-31 00:00:00', 'Asia/Baku')->timestamp, $execution[3]);
        $this->assertSame(CarbonImmutable::parse('2026-07-31 23:59:59', 'Asia/Baku')->timestamp, $execution[4]);
    }

    public function test_failed_report_does_not_create_mass_no_data_facts(): void
    {
        [$handler, $run, $task] = $this->handlerScenario(new RuntimeException('Wialon API unavailable'));

        try {
            $handler->execute($run, $task);
            $this->fail('The failed Wialon report should stop synchronization.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Wialon API unavailable', $exception->getMessage());
        }

        $this->assertSame(0, NightDayEfficiencyDailyFact::query()->count());
        $syncTask = NightDayEfficiencySyncTask::query()->where('project_id', $task->project_id)->first();
        $this->assertNotNull($syncTask);
        $this->assertTrue($syncTask->business_date->isSameDay('2026-07-31'));
        $this->assertSame('failed', $syncTask->status);
    }

    public function test_forced_sync_replaces_only_night_day_facts_for_the_calendar_business_date(): void
    {
        [$handler, $run, $task, $project] = $this->handlerScenario($this->report('6001', '10,01', '14,25 km'));
        $this->daytimeFact($project);
        $this->nighttimeFact($project);

        $this->assertSame(2, $handler->execute($run, $task));
        $this->assertDatabaseHas('night_day_efficiency_daily_facts', [
            'business_date' => '2026-07-31',
            'wialon_unit_id' => '6001',
            'efficiency_status' => EfficiencyStatus::OVER_TEN,
            'engine_seconds' => 36036,
            'source_report_template_id' => 22,
            'source_report_name' => 'night day report Engine hours (api)',
            'started_at' => '2026-07-31 00:10:00',
            'ended_at' => '2026-07-31 23:50:00',
        ]);
        $this->assertDatabaseHas('night_day_efficiency_daily_facts', [
            'business_date' => '2026-07-31',
            'wialon_unit_id' => '6002',
            'efficiency_status' => EfficiencyStatus::NO_DATA,
            'engine_seconds' => 0,
        ]);
        $this->assertDatabaseMissing('night_day_efficiency_daily_facts', [
            'business_date' => '2026-08-01',
            'wialon_unit_id' => '6001',
        ]);
        $this->assertSame(1, DaytimeEfficiencyDailyFact::query()->count());
        $this->assertSame(1, NighttimeEfficiencyDailyFact::query()->count());
        $firstIds = NightDayEfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();

        $handler->execute($run->refresh(), $task->refresh());

        $this->assertSame(2, NightDayEfficiencyDailyFact::query()->count());
        $secondIds = NightDayEfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertSame(1, DaytimeEfficiencyDailyFact::query()->count());
        $this->assertSame(1, NighttimeEfficiencyDailyFact::query()->count());
    }

    public function test_api_drilldown_counts_unique_units_and_assigns_multi_day_status_from_average(): void
    {
        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Night day API', 'active' => true]);
        $this->nightDayFact($project, '7001', '2026-07-30', 4.0, EfficiencyStatus::ONE_TO_SEVEN);
        $this->nightDayFact($project, '7001', '2026-07-31', 10.0, EfficiencyStatus::SEVEN_TO_TEN);

        $query = ['date_from' => '2026-07-30', 'date_to' => '2026-07-31', 'status' => EfficiencyStatus::SEVEN_TO_TEN];
        $this->actingAs($user)->getJson(route('api.dashboard.night-day-efficiency.summary', $query))
            ->assertOk()
            ->assertJsonPath('data.2.count', 1);
        $this->actingAs($user)->getJson(route('api.dashboard.night-day-efficiency.projects', $query))
            ->assertOk()
            ->assertJsonPath('data.0.project', 'Night day API')
            ->assertJsonPath('data.0.unique_units_count', 1)
            ->assertJsonPath('data.0.average_engine_hours', '7.00');
        $this->actingAs($user)->getJson(route('api.dashboard.night-day-efficiency.units', $query))
            ->assertOk()
            ->assertJsonPath('data.0.synced_days_count', 2)
            ->assertJsonPath('data.0.total_engine_hours_decimal', 14)
            ->assertJsonPath('data.0.average_engine_hours_decimal', 7);

        $export = app(NightDayEfficiencyDashboardService::class)->export($query);
        $this->assertSame(['Xülasə', 'Texnika üzrə', 'Gündəlik detallar'], array_column($export['sheets'], 'name'));
        $this->assertSame('night day report Engine hours (api)', $export['filters'][4][1]);
    }

    public function test_sync_daily_command_includes_night_day_stage_without_cross_midnight_nighttime(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-02 00:00:00', 'Asia/Baku'));

        try {
            $project = Project::query()->create(['name' => 'Night day daily command', 'active' => true]);
            $group = ProjectWialonGroup::query()->create([
                'project_id' => $project->id,
                'wialon_group_id' => '711',
                'name' => 'Night day daily command - NWC',
                'ownership_type' => Equipment::OWNERSHIP_NWC,
            ]);
            $this->equipment($project, $group, Equipment::OWNERSHIP_NWC, '71101');

            $this->artisan('dashboard-reports:sync-daily')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $pipelines = json_decode((string) \App\Models\Setting::query()
            ->where('key', 'dashboard_report_pipelines')
            ->value('value'), true);

        $sections = collect($pipelines[0]['plans'])->pluck('section')->all();
        $this->assertContains(HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY, $sections);
        $this->assertNotContains(HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY, $sections);
    }

    public function test_cancelling_historical_run_closes_night_day_sync_run(): void
    {
        $historicalRun = HistoricalRecalculation::query()->create([
            'uuid' => fake()->uuid(),
            'signature' => sha1(fake()->uuid()),
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'timezone' => 'Asia/Baku',
            'force' => true,
            'project_ids' => [],
        ]);
        NightDayEfficiencySyncRun::query()->create([
            'historical_recalculation_id' => $historicalRun->id,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
        ]);

        app(HistoricalRecalculationService::class)->cancel($historicalRun);

        $this->assertDatabaseHas('night_day_efficiency_sync_runs', [
            'historical_recalculation_id' => $historicalRun->id,
            'status' => HistoricalRecalculation::STATUS_CANCELLED,
        ]);
    }

    /** @return array{NightDayEfficiencyRecalculationHandler, HistoricalRecalculation, HistoricalRecalculationTask, Project} */
    private function handlerScenario(array|RuntimeException $report): array
    {
        $project = Project::query()->create(['name' => 'Night day sync project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'name' => 'Night day sync project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        foreach (['6001', '6002'] as $unitId) {
            Equipment::query()->create([
                'name' => 'Unit '.$unitId,
                'wialon_unit_id' => $unitId,
                'equipment_type_id' => $type->id,
                'project_id' => $project->id,
                'project_wialon_group_id' => $group->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'active' => true,
            ]);
        }

        $run = HistoricalRecalculation::query()->create([
            'uuid' => fake()->uuid(),
            'signature' => sha1(fake()->uuid()),
            'status' => 'running',
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'timezone' => 'Asia/Baku',
            'force' => true,
            'project_ids' => [$project->id],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => 'running',
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-31',
            'project_id' => $project->id,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getUnitGroups')->andReturn([['id' => 9001, 'u' => [6001, 6002]]]);
        $reports = Mockery::mock(WialonNightDayEfficiencyReportService::class);
        $reports->shouldReceive('settings')->andReturn([
            'resource_id' => 601701680,
            'template_id' => 22,
            'template_name' => 'night day report Engine hours (api)',
        ]);
        $report instanceof RuntimeException
            ? $reports->shouldReceive('execute')->andThrow($report)
            : $reports->shouldReceive('execute')->andReturn($report);
        $sessions = Mockery::mock(WialonSessionManager::class);
        $sessions->shouldReceive('sid')->andReturn('test-session');
        $sessions->shouldReceive('close')->zeroOrMoreTimes();

        return [new NightDayEfficiencyRecalculationHandler(
            $wialon,
            $reports,
            app(WialonNightDayEfficiencyReportParser::class),
            $sessions,
        ), $run, $task, $project];
    }

    private function report(string $unitId, string $hours, string $mileage): array
    {
        return ['tables' => [[
            'index' => 0,
            'table' => [
                'header' => ['Grouping', 'Engine hours', 'Начало', 'Конец', 'Пробег'],
                'header_type' => ['', 'duration', 'time_begin', 'time_end', 'mileage'],
                'rows' => 1,
            ],
            'rows' => [[
                'uid' => (int) $unitId,
                'c' => [
                    'Unit '.$unitId,
                    $hours,
                    ['t' => '2026-07-30 20:10:00', 'v' => CarbonImmutable::parse('2026-07-31 00:10:00', 'Asia/Baku')->timestamp, 'u' => (int) $unitId],
                    ['t' => '2026-07-31 19:50:00', 'v' => CarbonImmutable::parse('2026-07-31 23:50:00', 'Asia/Baku')->timestamp, 'u' => (int) $unitId],
                    $mileage,
                ],
            ]],
        ]]];
    }

    private function nightDayFact(Project $project, string $unitId, string $date, float $hours, string $status): void
    {
        NightDayEfficiencyDailyFact::query()->create([
            'business_date' => $date,
            'project_id' => $project->id,
            'wialon_group_id' => 'night-day-9001',
            'wialon_unit_id' => $unitId,
            'unit_name' => 'Unit '.$unitId,
            'vehicle_type' => 'Loader',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => $hours,
            'engine_seconds' => (int) round($hours * 3600),
            'engine_hours_raw' => number_format($hours, 2, '.', ''),
            'started_at' => $date.' 00:10:00',
            'ended_at' => $date.' 23:50:00',
            'mileage_km' => 12.75,
            'mileage_raw' => '12,75 km',
            'efficiency_status' => $status,
            'source_report_template_id' => 22,
            'source_report_name' => 'night day report Engine hours (api)',
            'source_table_index' => 0,
        ]);
    }

    private function daytimeFact(Project $project): void
    {
        DaytimeEfficiencyDailyFact::query()->create([
            'business_date' => '2026-07-31',
            'project_id' => $project->id,
            'wialon_group_id' => 'day-9001',
            'wialon_unit_id' => 'day-6001',
            'unit_name' => 'Day unit',
            'vehicle_type' => 'Dump Truck',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 6,
            'engine_seconds' => 21600,
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
            'source_report_template_id' => 10,
            'source_report_name' => 'day report Engine hours (api)',
        ]);
    }

    private function nighttimeFact(Project $project): void
    {
        NighttimeEfficiencyDailyFact::query()->create([
            'shift_date' => '2026-07-31',
            'shift_started_at' => '2026-07-31 18:00:00',
            'shift_ended_at' => '2026-08-01 07:59:59',
            'project_id' => $project->id,
            'wialon_group_id' => 'night-9001',
            'wialon_unit_id' => 'night-6001',
            'unit_name' => 'Night unit',
            'vehicle_type' => 'Dump Truck',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 4,
            'engine_seconds' => 14400,
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
            'source_report_template_id' => 18,
            'source_report_name' => 'night report Engine hours (api)',
        ]);
    }

    private function equipment(Project $project, ProjectWialonGroup $group, string $ownership, string $unitId): Equipment
    {
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Road Roller']);

        return Equipment::query()->create([
            'name' => 'Pipeline unit '.$unitId,
            'wialon_unit_id' => $unitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => $ownership,
            'active' => true,
        ]);
    }
}
