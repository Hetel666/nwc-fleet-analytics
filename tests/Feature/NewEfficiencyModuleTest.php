<?php

namespace Tests\Feature;

use App\Models\DailyUnitAggregate;
use App\Models\EfficiencyDailyFact;
use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Models\WialonReportSyncItem;
use App\Services\EfficiencyDashboardService;
use App\Services\EfficiencyRecalculationHandler;
use App\Services\WialonEfficiencyReportParser;
use App\Services\WialonEfficiencyReportService;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use App\Services\WialonSessionManager;
use App\Services\XlsxExportService;
use App\Support\EfficiencyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class NewEfficiencyModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_normalizes_decimal_comma_mileage_and_wialon_metadata(): void
    {
        $parsed = app(WialonEfficiencyReportParser::class)->parse($this->report('6001', '10,73', '112,84 km'));
        $record = $parsed['records'][0];

        $this->assertSame('6001', $record['wialon_unit_id']);
        $this->assertSame(10.73, $record['engine_hours_decimal']);
        $this->assertSame(38628, $record['engine_seconds']);
        $this->assertSame(112.84, $record['mileage_km']);
        $this->assertSame('112,84 km', $record['mileage_raw']);
        $this->assertSame(1, $parsed['rows_received']);
    }

    public function test_status_boundaries_use_raw_seconds(): void
    {
        $this->assertSame(EfficiencyStatus::NO_DATA, EfficiencyStatus::classify(0));
        $this->assertSame(EfficiencyStatus::ZERO_TO_ONE, EfficiencyStatus::classify(1800));
        $this->assertSame(EfficiencyStatus::ONE_TO_SEVEN, EfficiencyStatus::classify(3600));
        $this->assertSame(EfficiencyStatus::ONE_TO_SEVEN, EfficiencyStatus::classify((int) round(6.99 * 3600)));
        $this->assertSame(EfficiencyStatus::SEVEN_TO_TEN, EfficiencyStatus::classify(7 * 3600));
        $this->assertSame(EfficiencyStatus::SEVEN_TO_TEN, EfficiencyStatus::classify(10 * 3600));
        $this->assertSame(EfficiencyStatus::OVER_TEN, EfficiencyStatus::classify((int) round(10.01 * 3600)));
    }

    public function test_forced_sync_replaces_existing_facts_with_new_rows(): void
    {
        [$handler, $run, $task] = $this->handlerScenario($this->report('6001', '7,50', '4,25 km'));

        $this->assertSame(2, $handler->execute($run, $task));
        $this->assertSame(2, EfficiencyDailyFact::query()->count());
        $this->assertDatabaseHas('efficiency_daily_facts', [
            'wialon_unit_id' => '6001',
            'efficiency_status' => EfficiencyStatus::SEVEN_TO_TEN,
            'engine_seconds' => 27000,
        ]);
        $this->assertDatabaseHas('efficiency_daily_facts', [
            'wialon_unit_id' => '6002',
            'efficiency_status' => EfficiencyStatus::NO_DATA,
            'engine_seconds' => 0,
        ]);
        $this->assertSame(1, EquipmentDailyStat::query()->count());
        $this->assertSame(1, DailyUnitAggregate::query()->count());
        $this->assertSame(1, EngineHoursReportUnitDay::query()->count());
        $this->assertSame(1, WialonReportSyncItem::query()->count());
        $this->assertSame(7.5, (float) EquipmentDailyStat::query()->value('worked_hours'));
        $this->assertSame(4.25, (float) DailyUnitAggregate::query()->value('mileage'));
        $this->assertSame('Qrup report Engine hours (api)', EngineHoursReportUnitDay::query()->value('report_template_name'));
        $firstIds = EfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();

        $handler->execute($run->refresh(), $task->refresh());

        $this->assertSame(2, EfficiencyDailyFact::query()->count());
        $this->assertSame(1, EngineHoursReportUnitDay::query()->count());
        $secondIds = EfficiencyDailyFact::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
    }

    public function test_forced_sync_report_failure_preserves_existing_facts(): void
    {
        [$handler, $run, $task] = $this->handlerScenario(new RuntimeException('Wialon report failed'));
        $project = Project::query()->firstOrFail();
        $this->fact(
            $project,
            '6001',
            '2026-07-31',
            Equipment::OWNERSHIP_NWC,
            'Dump Truck',
            EfficiencyStatus::ONE_TO_SEVEN,
            4,
        );
        $existingId = EfficiencyDailyFact::query()->value('id');

        try {
            $handler->execute($run, $task);
            $this->fail('Expected report failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Wialon report failed', $exception->getMessage());
        }

        $this->assertSame(1, EfficiencyDailyFact::query()->count());
        $this->assertSame($existingId, EfficiencyDailyFact::query()->value('id'));
        $this->assertSame(4.0, (float) EfficiencyDailyFact::query()->value('engine_hours_decimal'));
        $this->assertDatabaseHas('efficiency_sync_tasks', ['status' => 'failed']);
    }

    public function test_confirmed_empty_report_creates_no_data_for_full_group_membership(): void
    {
        [$handler, $run, $task] = $this->handlerScenario([
            'tables' => [],
            'result' => ['reportResult' => ['tables' => []]],
        ]);

        $this->assertSame(2, $handler->execute($run, $task));
        $this->assertSame(2, EfficiencyDailyFact::query()->count());
        $this->assertSame(2, EfficiencyDailyFact::query()->where('efficiency_status', EfficiencyStatus::NO_DATA)->count());
        $this->assertDatabaseHas('efficiency_sync_tasks', [
            'status' => 'completed',
            'report_rows_received' => 0,
            'missing_units_count' => 2,
        ]);
    }

    public function test_incomplete_report_columns_do_not_create_mass_no_data(): void
    {
        $report = $this->report('6001', '7,50', '4,25 km');
        array_pop($report['tables'][0]['table']['header']);
        array_pop($report['tables'][0]['table']['header_type']);
        array_pop($report['tables'][0]['rows'][0]['c']);
        [$handler, $run, $task] = $this->handlerScenario($report);

        try {
            $handler->execute($run, $task);
            $this->fail('Expected an incomplete report failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('required columns', $exception->getMessage());
        }

        $this->assertSame(0, EfficiencyDailyFact::query()->count());
        $this->assertDatabaseHas('efficiency_sync_tasks', ['status' => 'failed']);
    }

    public function test_partial_wialon_pagination_is_rejected(): void
    {
        config()->set('fleet.wialon.efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.efficiency_report_template_id', 19);
        config()->set('fleet.wialon.efficiency_report_template_name', 'Qrup report Engine hours (api)');

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')->once()->andReturn([
            'resource_id' => 601701680,
            'id' => 19,
            'type' => 'avl_unit_group',
        ]);
        $wialon->shouldReceive('cleanupReportResult')->twice();
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => ['tables' => [[
                'header' => ['Grouping', 'Engine hours', 'Begin', 'End', 'Mileage'],
                'header_type' => ['', 'duration', 'time_begin', 'time_end', 'mileage'],
                'rows' => 2,
            ]]],
        ]);
        $wialon->shouldReceive('selectReportResultRows')->once()->andReturn([
            $this->report('6001', '1,25', '2,50 km')['tables'][0]['rows'][0],
        ]);
        $lock = Mockery::mock(WialonReportSessionLock::class);
        $lock->shouldReceive('run')->once()->andReturnUsing(fn ($callback) => $callback());
        $group = new ProjectWialonGroup(['wialon_group_id' => '9001']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned 1 of 2 rows');

        (new WialonEfficiencyReportService($wialon, $lock))->execute(
            $group,
            now('Asia/Baku')->startOfDay(),
            now('Asia/Baku')->endOfDay(),
            'test-session',
        );
    }

    public function test_dashboard_counts_equipment_days_separates_ownership_and_excludes_other_types(): void
    {
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $this->fact($project, '6001', '2026-07-30', Equipment::OWNERSHIP_NWC, 'Dump Truck', EfficiencyStatus::SEVEN_TO_TEN, 8);
        $this->fact($project, '6001', '2026-07-31', Equipment::OWNERSHIP_NWC, 'Dump Truck', EfficiencyStatus::OVER_TEN, 11);
        $this->fact($project, '6002', '2026-07-31', Equipment::OWNERSHIP_ICARE, 'Excavator', EfficiencyStatus::NO_DATA, 0);

        $dashboard = app(EfficiencyDashboardService::class);
        $nwc = $dashboard->summaryForOwnership(['from' => '2026-07-30', 'to' => '2026-07-31'], Equipment::OWNERSHIP_NWC);
        $icare = $dashboard->summaryForOwnership(['from' => '2026-07-30', 'to' => '2026-07-31'], Equipment::OWNERSHIP_ICARE);

        $this->assertSame(2, $nwc['total']);
        $this->assertSame(1, $nwc[EfficiencyStatus::SEVEN_TO_TEN]);
        $this->assertSame(1, $nwc[EfficiencyStatus::OVER_TEN]);
        $this->assertSame(1, $icare[EfficiencyStatus::NO_DATA]);
    }

    public function test_efficiency_api_and_excel_have_required_drilldown_and_sheets(): void
    {
        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Project API', 'active' => true]);
        $this->fact($project, '7001', '2026-07-31', Equipment::OWNERSHIP_NWC, 'Loader', EfficiencyStatus::ONE_TO_SEVEN, 4.5);

        $this->actingAs($user)->getJson(route('api.dashboard.efficiency.summary', [
            'date_from' => '2026-07-31', 'date_to' => '2026-07-31',
        ]))->assertOk()->assertJsonPath('data.1.status', EfficiencyStatus::ONE_TO_SEVEN)
            ->assertJsonPath('data.1.count', 1);

        $this->actingAs($user)->getJson(route('api.dashboard.efficiency.projects', [
            'date_from' => '2026-07-31', 'date_to' => '2026-07-31', 'status' => EfficiencyStatus::ONE_TO_SEVEN,
        ]))->assertOk()->assertJsonPath('data.0.equipment_days_count', 1);

        $this->actingAs($user)->getJson(route('api.dashboard.efficiency.units', [
            'date_from' => '2026-07-31', 'date_to' => '2026-07-31', 'status' => EfficiencyStatus::ONE_TO_SEVEN,
        ]))->assertOk()->assertJsonPath('data.0.engine_seconds', 16200)
            ->assertJsonPath('data.0.project', 'Project API');

        $export = app(EfficiencyDashboardService::class)->export(['from' => '2026-07-31', 'to' => '2026-07-31']);
        $this->assertSame(['Xülasə', 'Detallar'], array_column($export['sheets'], 'name'));
        $content = app(XlsxExportService::class)->build($export);
        $path = tempnam(sys_get_temp_dir(), 'efficiency-xlsx-');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('Xülasə', $workbook);
        $this->assertStringContainsString('Detallar', $workbook);
    }

    /** @return array{EfficiencyRecalculationHandler, HistoricalRecalculation, HistoricalRecalculationTask} */
    private function handlerScenario(array|RuntimeException $report): array
    {
        $project = Project::query()->create(['name' => 'Sync project', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '9001',
            'name' => 'Sync project - NWC',
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
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
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
        $reports = Mockery::mock(WialonEfficiencyReportService::class);
        $reports->shouldReceive('settings')->andReturn([
            'resource_id' => 601701680,
            'template_id' => 19,
            'template_name' => 'Qrup report Engine hours (api)',
        ]);

        if ($report instanceof RuntimeException) {
            $reports->shouldReceive('execute')->andThrow($report);
        } else {
            $reports->shouldReceive('execute')->andReturn($report);
        }

        $sessions = Mockery::mock(WialonSessionManager::class);
        $sessions->shouldReceive('sid')->andReturn('test-session');
        $sessions->shouldReceive('close')->zeroOrMoreTimes();

        return [new EfficiencyRecalculationHandler(
            $wialon,
            $reports,
            app(WialonEfficiencyReportParser::class),
            $sessions,
        ), $run, $task];
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
                    ['t' => '2026-07-31 08:00:00', 'v' => 1785484800, 'u' => (int) $unitId],
                    ['t' => '2026-07-31 18:00:00', 'v' => 1785520800, 'u' => (int) $unitId],
                    $mileage,
                ],
            ]],
        ]]];
    }

    private function fact(Project $project, string $unitId, string $date, string $ownership, string $type, string $status, float $hours): void
    {
        EfficiencyDailyFact::query()->create([
            'business_date' => $date,
            'project_id' => $project->id,
            'wialon_group_id' => 'group-'.$ownership,
            'wialon_unit_id' => $unitId,
            'unit_name' => 'Unit '.$unitId,
            'vehicle_type' => $type,
            'ownership' => $ownership,
            'engine_hours_decimal' => $hours,
            'engine_seconds' => (int) round($hours * 3600),
            'efficiency_status' => $status,
            'source_report_template_id' => 19,
            'source_report_name' => 'Qrup report Engine hours (api)',
        ]);
    }
}
