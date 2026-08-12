<?php

namespace Tests\Feature;

use App\Models\DaytimeEfficiencyDailyFact;
use App\Models\EfficiencyDailyFact;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\DaytimeEfficiencyDashboardService;
use App\Services\DaytimeEfficiencyRecalculationHandler;
use App\Services\WialonDaytimeEfficiencyReportParser;
use App\Services\WialonDaytimeEfficiencyReportService;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use App\Services\WialonSessionManager;
use App\Support\EfficiencyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DaytimeEfficiencyModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('Daytime efficiency dashboard was removed from the active module set.');
    }

    public function test_report_service_resolves_only_the_daytime_template(): void
    {
        config()->set('fleet.wialon.daytime_efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.daytime_efficiency_report_template_id', 10);
        config()->set('fleet.wialon.daytime_efficiency_report_template_name', 'day report Engine hours (api)');
        config()->set('historical_recalculation.timezone', 'Asia/Baku');

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')
            ->once()
            ->with(601701680, 'day report Engine hours (api)')
            ->andReturn(['resource_id' => 601701680, 'id' => 10, 'type' => 'avl_unit_group']);
        $lock = Mockery::mock(WialonReportSessionLock::class);

        $settings = (new WialonDaytimeEfficiencyReportService($wialon, $lock))->settings();

        $this->assertSame(601701680, $settings['resource_id']);
        $this->assertSame(10, $settings['template_id']);
        $this->assertSame('day report Engine hours (api)', $settings['template_name']);
    }

    public function test_report_service_converts_the_baku_shift_for_wialon_api_execution(): void
    {
        config()->set('fleet.wialon.daytime_efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.daytime_efficiency_report_template_id', 10);
        config()->set('fleet.wialon.daytime_efficiency_report_template_name', 'day report Engine hours (api)');
        config()->set('historical_recalculation.timezone', 'Asia/Baku');

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')->once()->andReturn([
            'resource_id' => 601701680,
            'id' => 10,
            'type' => 'avl_unit_group',
        ]);
        $wialon->shouldReceive('getReportTemplateData')->once()->andReturn([
            'tbl' => [[
                'n' => 'unit_group_engine_hours',
                'sch' => ['f1' => 480, 'f2' => 0, 't1' => 1079, 't2' => 0, 'm' => 0, 'y' => 0, 'w' => 0, 'fl' => 1],
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

        $result = (new WialonDaytimeEfficiencyReportService($wialon, $lock))
            ->execute($group, $date->startOfDay(), $date->endOfDay(), 'test-session');

        $this->assertSame(10, $result['template_id']);
        $this->assertSame('9001', $result['object_id']);
        $this->assertSame(601701680, $execution[0]);
        $this->assertSame(240, $execution[1]['tbl'][0]['sch']['f1']);
        $this->assertSame(839, $execution[1]['tbl'][0]['sch']['t1']);
        $this->assertSame(CarbonImmutable::parse('2026-07-31 00:00:00', 'Asia/Baku')->timestamp, $execution[3]);
        $this->assertSame(CarbonImmutable::parse('2026-07-31 23:59:59', 'Asia/Baku')->timestamp, $execution[4]);
        $this->assertSame(0, $execution[5]);
        $this->assertSame('test-session', $execution[6]);
    }

    public function test_forced_sync_replaces_existing_facts_and_does_not_change_other_modules(): void
    {
        [$handler, $run, $task, $project] = $this->handlerScenario($this->report('6001', '7,50', '4,25 km'));
        $this->ordinaryFact($project);

        $this->assertSame(2, $handler->execute($run, $task));
        $this->assertDatabaseHas('daytime_efficiency_daily_facts', [
            'wialon_unit_id' => '6001',
            'efficiency_status' => EfficiencyStatus::SEVEN_TO_TEN,
            'engine_seconds' => 27000,
            'source_report_template_id' => 10,
            'source_table_index' => 0,
            'started_at' => '2026-07-31 08:00:00',
            'ended_at' => '2026-07-31 17:59:00',
        ]);
        $this->assertDatabaseHas('daytime_efficiency_daily_facts', [
            'wialon_unit_id' => '6002',
            'efficiency_status' => EfficiencyStatus::NO_DATA,
            'engine_seconds' => 0,
        ]);
        $this->assertDatabaseMissing('daytime_efficiency_daily_facts', ['wialon_unit_id' => '6003']);
        $this->assertSame(1, EfficiencyDailyFact::query()->count());
        $this->assertSame(8.0, (float) EfficiencyDailyFact::query()->value('engine_hours_decimal'));
        $firstIds = DaytimeEfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();

        $handler->execute($run->refresh(), $task->refresh());

        $this->assertSame(2, DaytimeEfficiencyDailyFact::query()->count());
        $secondIds = DaytimeEfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertSame(1, EfficiencyDailyFact::query()->count());
    }

    public function test_forced_sync_report_failure_preserves_existing_facts(): void
    {
        [$handler, $run, $task, $project] = $this->handlerScenario(new RuntimeException('Daytime Wialon report failed'));
        DaytimeEfficiencyDailyFact::query()->create([
            'business_date' => '2026-07-31',
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'wialon_unit_id' => '6001',
            'unit_name' => 'Unit 6001',
            'vehicle_type' => 'Dump Truck',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 4,
            'engine_seconds' => 14400,
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
            'source_report_template_id' => 10,
            'source_report_name' => 'day report Engine hours (api)',
        ]);
        $existingId = DaytimeEfficiencyDailyFact::query()->value('id');

        try {
            $handler->execute($run, $task);
            $this->fail('Expected the daytime report to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Daytime Wialon report failed', $exception->getMessage());
        }

        $this->assertSame(1, DaytimeEfficiencyDailyFact::query()->count());
        $this->assertSame($existingId, DaytimeEfficiencyDailyFact::query()->value('id'));
        $this->assertSame(4.0, (float) DaytimeEfficiencyDailyFact::query()->value('engine_hours_decimal'));
        $this->assertDatabaseHas('daytime_efficiency_sync_tasks', ['status' => 'failed']);
    }

    public function test_separate_api_drilldown_and_excel_contract(): void
    {
        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Daytime API', 'active' => true]);
        DaytimeEfficiencyDailyFact::query()->create([
            'business_date' => '2026-07-31',
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'wialon_unit_id' => '7001',
            'unit_name' => 'Unit 7001',
            'vehicle_type' => 'Loader',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => 4.5,
            'engine_seconds' => 16200,
            'engine_hours_raw' => '4:30:00',
            'started_at' => '2026-07-31 08:00:00',
            'ended_at' => '2026-07-31 17:59:00',
            'mileage_km' => 12.75,
            'mileage_raw' => '12,75 km',
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
            'source_report_template_id' => 10,
            'source_report_name' => 'day report Engine hours (api)',
            'source_table_index' => 0,
        ]);

        $query = ['date_from' => '2026-07-31', 'date_to' => '2026-07-31', 'status' => EfficiencyStatus::ONE_TO_SEVEN];
        $this->actingAs($user)->getJson(route('api.dashboard.daytime-efficiency.summary', $query))
            ->assertOk()->assertJsonPath('data.1.count', 1);
        $this->actingAs($user)->getJson(route('api.dashboard.daytime-efficiency.projects', $query))
            ->assertOk()->assertJsonPath('data.0.project', 'Daytime API')
            ->assertJsonPath('data.0.equipment_days_count', 1);
        $this->actingAs($user)->getJson(route('api.dashboard.daytime-efficiency.units', $query))
            ->assertOk()->assertJsonPath('data.0.engine_seconds', 16200)
            ->assertJsonPath('data.0.mileage_km', 12.75);

        $export = app(DaytimeEfficiencyDashboardService::class)->export($query);
        $this->assertSame(['Xülasə', 'Gündüz detalları'], array_column($export['sheets'], 'name'));
        $this->assertSame('day report Engine hours (api)', $export['filters'][4][1]);
    }

    public function test_scheduler_queues_dashboard_reports_at_0000_baku(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($item): bool => str_contains($item->command ?? '', 'dashboard-reports:sync-daily'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertSame('Asia/Baku', $event->timezone);
    }

    /** @return array{DaytimeEfficiencyRecalculationHandler, HistoricalRecalculation, HistoricalRecalculationTask, Project} */
    private function handlerScenario(array|RuntimeException $report): array
    {
        $project = Project::query()->create(['name' => 'Daytime sync project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'name' => 'Daytime sync project - NWC',
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
            'dashboard_section' => HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
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
        $reports = Mockery::mock(WialonDaytimeEfficiencyReportService::class);
        $reports->shouldReceive('settings')->andReturn([
            'resource_id' => 601701680,
            'template_id' => 10,
            'template_name' => 'day report Engine hours (api)',
        ]);
        $report instanceof RuntimeException
            ? $reports->shouldReceive('execute')->andThrow($report)
            : $reports->shouldReceive('execute')->andReturn($report);
        $sessions = Mockery::mock(WialonSessionManager::class);
        $sessions->shouldReceive('sid')->andReturn('test-session');
        $sessions->shouldReceive('close')->zeroOrMoreTimes();

        return [new DaytimeEfficiencyRecalculationHandler(
            $wialon,
            $reports,
            app(WialonDaytimeEfficiencyReportParser::class),
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
                    ['t' => '2026-07-31 04:00:00', 'v' => CarbonImmutable::parse('2026-07-31 08:00:00', 'Asia/Baku')->timestamp, 'u' => (int) $unitId],
                    ['t' => '2026-07-31 13:59:00', 'v' => CarbonImmutable::parse('2026-07-31 17:59:00', 'Asia/Baku')->timestamp, 'u' => (int) $unitId],
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
}
