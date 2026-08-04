<?php

namespace Tests\Feature;

use App\Models\DaytimeEfficiencyDailyFact;
use App\Models\EfficiencyDailyFact;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\NighttimeEfficiencyDailyFact;
use App\Models\NighttimeEfficiencySyncRun;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\HistoricalRecalculationService;
use App\Services\NighttimeEfficiencyDashboardService;
use App\Services\NighttimeEfficiencyRecalculationHandler;
use App\Services\WialonNighttimeEfficiencyReportParser;
use App\Services\WialonNighttimeEfficiencyReportService;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use App\Services\WialonSessionManager;
use App\Support\EfficiencyStatus;
use App\Support\NighttimeShiftWindow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NighttimeEfficiencyModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_service_resolves_only_the_nighttime_template(): void
    {
        config()->set('fleet.wialon.nighttime_efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.nighttime_efficiency_report_template_id', 18);
        config()->set('fleet.wialon.nighttime_efficiency_report_template_name', 'night report Engine hours (api)');
        config()->set('historical_recalculation.timezone', 'Asia/Baku');

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')
            ->once()
            ->with(601701680, 'night report Engine hours (api)')
            ->andReturn(['resource_id' => 601701680, 'id' => 18, 'type' => 'avl_unit_group']);
        $lock = Mockery::mock(WialonReportSessionLock::class);

        $settings = (new WialonNighttimeEfficiencyReportService($wialon, $lock))->settings();

        $this->assertSame(601701680, $settings['resource_id']);
        $this->assertSame(18, $settings['template_id']);
        $this->assertSame('night report Engine hours (api)', $settings['template_name']);
    }

    public function test_report_service_converts_the_baku_shift_for_wialon_api_execution(): void
    {
        config()->set('fleet.wialon.nighttime_efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.nighttime_efficiency_report_template_id', 18);
        config()->set('fleet.wialon.nighttime_efficiency_report_template_name', 'night report Engine hours (api)');
        config()->set('historical_recalculation.timezone', 'Asia/Baku');

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')->once()->andReturn([
            'resource_id' => 601701680,
            'id' => 18,
            'type' => 'avl_unit_group',
        ]);
        $wialon->shouldReceive('getReportTemplateData')->once()->andReturn([
            'tbl' => [[
                'n' => 'unit_group_engine_hours',
                'sch' => ['f1' => 1080, 'f2' => 0, 't1' => 1439, 't2' => 479, 'm' => 0, 'y' => 0, 'w' => 0, 'fl' => 1],
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

        $result = (new WialonNighttimeEfficiencyReportService($wialon, $lock))
            ->execute($group, $date->setTime(18, 0), $date->addDay()->setTime(7, 59, 59), 'test-session');

        $this->assertSame(18, $result['template_id']);
        $this->assertSame('9001', $result['object_id']);
        $this->assertSame(601701680, $execution[0]);
        $this->assertSame(840, $execution[1]['tbl'][0]['sch']['f1']);
        $this->assertSame(1439, $execution[1]['tbl'][0]['sch']['t1']);
        $this->assertSame(0, $execution[1]['tbl'][0]['sch']['f2']);
        $this->assertSame(239, $execution[1]['tbl'][0]['sch']['t2']);
        $this->assertSame(CarbonImmutable::parse('2026-07-31 18:00:00', 'Asia/Baku')->timestamp, $execution[3]);
        $this->assertSame(CarbonImmutable::parse('2026-08-01 07:59:59', 'Asia/Baku')->timestamp, $execution[4]);
        $this->assertSame(0, $execution[5]);
        $this->assertSame('test-session', $execution[6]);
    }

    public function test_parser_prefers_exact_row_timestamps_over_ambiguous_display_cells(): void
    {
        config()->set('historical_recalculation.timezone', 'Asia/Baku');
        $report = $this->report('6001', '0.77', '4.38 km');
        $report['tables'][0]['rows'][0]['t1'] = CarbonImmutable::parse('2026-07-31 19:34:57', 'Asia/Baku')->timestamp;
        $report['tables'][0]['rows'][0]['t2'] = CarbonImmutable::parse('2026-07-31 20:37:07', 'Asia/Baku')->timestamp;
        $report['tables'][0]['rows'][0]['c'][2] = '2026-07-31 15:34:57';
        $report['tables'][0]['rows'][0]['c'][3] = '2026-07-31 16:37:07';

        $record = app(WialonNighttimeEfficiencyReportParser::class)->parse($report)['records'][0];

        $this->assertSame('2026-07-31 19:34:57', $record['started_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-31 20:37:07', $record['ended_at']->format('Y-m-d H:i:s'));
    }

    public function test_forced_sync_replaces_existing_facts_and_does_not_change_other_modules(): void
    {
        [$handler, $run, $task, $project] = $this->handlerScenario($this->report('6001', '7,50', '4,25 km'));
        $this->ordinaryFact($project);
        $this->daytimeFact($project);

        $this->assertSame(2, $handler->execute($run, $task));
        $this->assertDatabaseHas('nighttime_efficiency_daily_facts', [
            'wialon_unit_id' => '6001',
            'efficiency_status' => EfficiencyStatus::SEVEN_TO_TEN,
            'engine_seconds' => 27000,
            'source_report_template_id' => 18,
            'source_table_index' => 0,
            'shift_started_at' => '2026-07-31 18:00:00',
            'shift_ended_at' => '2026-08-01 07:59:59',
            'started_at' => '2026-07-31 18:00:00',
            'ended_at' => '2026-08-01 07:59:00',
        ]);
        $this->assertDatabaseHas('nighttime_efficiency_daily_facts', [
            'wialon_unit_id' => '6002',
            'efficiency_status' => EfficiencyStatus::NO_DATA,
            'engine_seconds' => 0,
        ]);
        $this->assertDatabaseMissing('nighttime_efficiency_daily_facts', ['wialon_unit_id' => '6003']);
        $this->assertSame(1, EfficiencyDailyFact::query()->count());
        $this->assertSame(8.0, (float) EfficiencyDailyFact::query()->value('engine_hours_decimal'));
        $this->assertSame(6.0, (float) DaytimeEfficiencyDailyFact::query()->value('engine_hours_decimal'));
        $firstIds = NighttimeEfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();

        $handler->execute($run->refresh(), $task->refresh());

        $this->assertSame(2, NighttimeEfficiencyDailyFact::query()->count());
        $secondIds = NighttimeEfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertSame(1, EfficiencyDailyFact::query()->count());
        $this->assertSame(1, DaytimeEfficiencyDailyFact::query()->count());
    }

    public function test_forced_sync_report_failure_preserves_existing_facts(): void
    {
        [$handler, $run, $task, $project] = $this->handlerScenario(new RuntimeException('Nighttime Wialon report failed'));
        NighttimeEfficiencyDailyFact::query()->create([
            'shift_date' => '2026-07-31',
            'shift_started_at' => '2026-07-31 18:00:00',
            'shift_ended_at' => '2026-08-01 07:59:59',
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'wialon_unit_id' => '6001',
            'unit_name' => 'Unit 6001',
            'vehicle_type' => 'Dump Truck',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 4,
            'engine_seconds' => 14400,
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
            'source_report_template_id' => 18,
            'source_report_name' => 'night report Engine hours (api)',
        ]);
        $existingId = NighttimeEfficiencyDailyFact::query()->value('id');

        try {
            $handler->execute($run, $task);
            $this->fail('Expected the nighttime report to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nighttime Wialon report failed', $exception->getMessage());
        }

        $this->assertSame(1, NighttimeEfficiencyDailyFact::query()->count());
        $this->assertSame($existingId, NighttimeEfficiencyDailyFact::query()->value('id'));
        $this->assertSame(4.0, (float) NighttimeEfficiencyDailyFact::query()->value('engine_hours_decimal'));
        $this->assertDatabaseHas('nighttime_efficiency_sync_tasks', ['status' => 'failed']);
    }

    public function test_separate_api_drilldown_and_excel_contract(): void
    {
        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Nighttime API', 'active' => true]);
        NighttimeEfficiencyDailyFact::query()->create([
            'shift_date' => '2026-07-31',
            'shift_started_at' => '2026-07-31 18:00:00',
            'shift_ended_at' => '2026-08-01 07:59:59',
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'wialon_unit_id' => '7001',
            'unit_name' => 'Unit 7001',
            'vehicle_type' => 'Loader',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 4.5,
            'engine_seconds' => 16200,
            'engine_hours_raw' => '4:30:00',
            'started_at' => '2026-07-31 18:00:00',
            'ended_at' => '2026-08-01 07:59:00',
            'mileage_km' => 12.75,
            'mileage_raw' => '12,75 km',
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
            'source_report_template_id' => 18,
            'source_report_name' => 'night report Engine hours (api)',
            'source_table_index' => 0,
        ]);

        $query = ['date_from' => '2026-07-31', 'date_to' => '2026-07-31', 'status' => EfficiencyStatus::ONE_TO_SEVEN];
        $this->actingAs($user)->getJson(route('api.dashboard.nighttime-efficiency.summary', $query))
            ->assertOk()->assertJsonPath('data.1.count', 1);
        $this->actingAs($user)->getJson(route('api.dashboard.nighttime-efficiency.projects', $query))
            ->assertOk()->assertJsonPath('data.0.project', 'Nighttime API')
            ->assertJsonPath('data.0.equipment_shifts_count', 1);
        $this->actingAs($user)->getJson(route('api.dashboard.nighttime-efficiency.units', $query))
            ->assertOk()->assertJsonPath('data.0.engine_seconds', 16200)
            ->assertJsonPath('data.0.mileage_km', 12.75);

        $export = app(NighttimeEfficiencyDashboardService::class)->export($query);
        $this->assertSame(['Xülasə', 'Gecə detalları'], array_column($export['sheets'], 'name'));
        $this->assertSame('night report Engine hours (api)', $export['filters'][4][1]);
    }

    public function test_scheduler_uses_dashboard_report_pipeline_and_night_shift_at_baku_times(): void
    {
        $events = collect(app(Schedule::class)->events());
        $dailyEvent = $events->first(fn ($item): bool => str_contains($item->command ?? '', 'dashboard-reports:sync-daily'));
        $nightEvent = $events->first(fn ($item): bool => str_contains($item->command ?? '', 'nighttime-efficiency:sync-last-completed-shift'));
        $tickEvent = $events->first(fn ($item): bool => str_contains($item->command ?? '', 'dashboard-reports:pipeline-tick'));
        $capacityEvent = $events->first(fn ($item): bool => str_contains($item->command ?? '', 'fleet:capacity-check'));
        $pruneEvent = $events->first(fn ($item): bool => str_contains($item->command ?? '', 'fleet:prune-dashboard-exports --skip-when-sync-active'));

        $this->assertNotNull($dailyEvent);
        $this->assertSame('0 0 * * *', $dailyEvent->expression);
        $this->assertSame('Asia/Baku', $dailyEvent->timezone);
        $this->assertNotNull($nightEvent);
        $this->assertSame('30 8 * * *', $nightEvent->expression);
        $this->assertSame('Asia/Baku', $nightEvent->timezone);
        $this->assertNotNull($tickEvent);
        $this->assertSame('0 * * * *', $tickEvent->expression);
        $this->assertSame('Asia/Baku', $tickEvent->timezone);
        $this->assertNotNull($capacityEvent);
        $this->assertSame('0 * * * *', $capacityEvent->expression);
        $this->assertSame('Asia/Baku', $capacityEvent->timezone);
        $this->assertNotNull($pruneEvent);
        $this->assertSame('30 4 * * *', $pruneEvent->expression);
        $this->assertSame('Asia/Baku', $pruneEvent->timezone);
    }

    public function test_shift_window_crosses_month_year_and_leap_day_boundaries(): void
    {
        foreach ([
            ['2026-07-31', '2026-07-31 18:00:00', '2026-08-01 07:59:59'],
            ['2026-12-31', '2026-12-31 18:00:00', '2027-01-01 07:59:59'],
            ['2028-02-29', '2028-02-29 18:00:00', '2028-03-01 07:59:59'],
        ] as [$shiftDate, $expectedStart, $expectedEnd]) {
            $window = NighttimeShiftWindow::forDate($shiftDate, 'Asia/Baku');

            $this->assertSame($expectedStart, $window['start']->format('Y-m-d H:i:s'));
            $this->assertSame($expectedEnd, $window['end']->format('Y-m-d H:i:s'));
            $this->assertSame('Asia/Baku', $window['start']->timezoneName);
            $this->assertSame('Asia/Baku', $window['end']->timezoneName);
        }
    }

    public function test_shift_date_boundary_points_are_inclusive_and_0800_is_excluded(): void
    {
        $this->assertSame('2026-07-31', NighttimeShiftWindow::shiftDateFor('2026-07-31 18:00:00'));
        $this->assertSame('2026-07-31', NighttimeShiftWindow::shiftDateFor('2026-07-31 23:59:59'));
        $this->assertSame('2026-07-31', NighttimeShiftWindow::shiftDateFor('2026-08-01 00:00:00'));
        $this->assertSame('2026-07-31', NighttimeShiftWindow::shiftDateFor('2026-08-01 07:59:59'));
        $this->assertNull(NighttimeShiftWindow::shiftDateFor('2026-08-01 08:00:00'));
    }

    public function test_more_than_ten_hours_is_a_valid_nighttime_status(): void
    {
        $this->assertSame(EfficiencyStatus::OVER_TEN, EfficiencyStatus::classify(36001));
    }

    public function test_automatic_command_does_not_duplicate_a_completed_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 08:30:00', 'Asia/Baku'));
        $shiftDate = now('Asia/Baku')->subDay()->toDateString();

        try {
            HistoricalRecalculation::query()->create([
                'uuid' => fake()->uuid(),
                'signature' => sha1(fake()->uuid()),
                'status' => HistoricalRecalculation::STATUS_COMPLETED,
                'dashboard_section' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
                'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
                'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                'date_from' => $shiftDate,
                'date_to' => $shiftDate,
                'timezone' => 'Asia/Baku',
                'force' => false,
                'project_ids' => [],
            ]);

            $this->artisan('nighttime-efficiency:sync-last-completed-shift')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, HistoricalRecalculation::query()
            ->where('dashboard_section', HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY)
            ->whereDate('date_from', $shiftDate)
            ->count());
    }

    public function test_cancelling_historical_run_closes_nighttime_sync_run(): void
    {
        $historicalRun = HistoricalRecalculation::query()->create([
            'uuid' => fake()->uuid(),
            'signature' => sha1(fake()->uuid()),
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'timezone' => 'Asia/Baku',
            'force' => true,
            'project_ids' => [],
        ]);
        NighttimeEfficiencySyncRun::query()->create([
            'historical_recalculation_id' => $historicalRun->id,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-31',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
        ]);

        app(HistoricalRecalculationService::class)->cancel($historicalRun);

        $this->assertDatabaseHas('nighttime_efficiency_sync_runs', [
            'historical_recalculation_id' => $historicalRun->id,
            'status' => HistoricalRecalculation::STATUS_CANCELLED,
        ]);
    }

    public function test_automatic_command_queues_the_previous_baku_shift_date(): void
    {
        Queue::fake();
        $project = Project::query()->create(['name' => 'Night auto project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Road Roller']);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '9901',
            'name' => 'Night auto project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '9902',
            'name' => 'Night auto project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'is_active' => true,
        ]);
        Equipment::query()->create([
            'name' => 'Auto unit',
            'wialon_unit_id' => '99001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-02 08:30:00', 'Asia/Baku'));
        $expectedShiftDate = '2026-08-01';

        try {
            $this->artisan('nighttime-efficiency:sync-last-completed-shift')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $run = HistoricalRecalculation::query()
            ->where('dashboard_section', HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($expectedShiftDate, $run->date_from->toDateString());
        $this->assertSame($expectedShiftDate, $run->date_to->toDateString());
        $this->assertDatabaseHas('historical_recalculation_tasks', [
            'historical_recalculation_id' => $run->id,
            'project_id' => $project->id,
            'stat_date' => $expectedShiftDate.' 00:00:00',
            'ownership_type' => null,
        ]);
        $this->assertSame(1, $run->tasks()->count());
    }

    /** @return array{NighttimeEfficiencyRecalculationHandler, HistoricalRecalculation, HistoricalRecalculationTask, Project} */
    private function handlerScenario(array|RuntimeException $report): array
    {
        $project = Project::query()->create(['name' => 'Nighttime sync project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'name' => 'Nighttime sync project - NWC',
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
        $disallowedType = EquipmentType::query()->create(['name' => 'Electric Generator']);
        Equipment::query()->create([
            'name' => 'Unit 6003',
            'wialon_unit_id' => '6003',
            'equipment_type_id' => $disallowedType->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        $run = HistoricalRecalculation::query()->create([
            'uuid' => fake()->uuid(),
            'signature' => sha1(fake()->uuid()),
            'status' => 'running',
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
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
        $wialon->shouldReceive('getUnitGroups')->andReturn([['id' => 9001, 'u' => [6001, 6002, 6003]]]);
        $reports = Mockery::mock(WialonNighttimeEfficiencyReportService::class);
        $reports->shouldReceive('settings')->andReturn([
            'resource_id' => 601701680,
            'template_id' => 18,
            'template_name' => 'night report Engine hours (api)',
        ]);
        $report instanceof RuntimeException
            ? $reports->shouldReceive('execute')->andThrow($report)
            : $reports->shouldReceive('execute')->andReturn($report);
        $sessions = Mockery::mock(WialonSessionManager::class);
        $sessions->shouldReceive('sid')->andReturn('test-session');
        $sessions->shouldReceive('close')->zeroOrMoreTimes();

        return [new NighttimeEfficiencyRecalculationHandler(
            $wialon,
            $reports,
            app(WialonNighttimeEfficiencyReportParser::class),
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
                    ['t' => '2026-07-31 14:00:00', 'v' => CarbonImmutable::parse('2026-07-31 18:00:00', 'Asia/Baku')->timestamp, 'u' => (int) $unitId],
                    ['t' => '2026-08-01 03:59:00', 'v' => CarbonImmutable::parse('2026-08-01 07:59:00', 'Asia/Baku')->timestamp, 'u' => (int) $unitId],
                    $mileage,
                ],
            ]],
        ]]];
    }

    private function ordinaryFact(Project $project): void
    {
        EfficiencyDailyFact::query()->create([
            'business_date' => '2026-07-31',
            'project_id' => $project->id,
            'wialon_group_id' => 'ordinary-9001',
            'wialon_unit_id' => 'ordinary-6001',
            'unit_name' => 'Ordinary unit',
            'vehicle_type' => 'Dump Truck',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 8,
            'engine_seconds' => 28800,
            'efficiency_status' => EfficiencyStatus::SEVEN_TO_TEN,
            'source_report_template_id' => 19,
            'source_report_name' => 'Qrup report Engine hours (api)',
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
}
