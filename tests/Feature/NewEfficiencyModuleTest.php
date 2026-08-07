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
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
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

    public function test_parser_reads_group_date_engine_hours_children_with_locations(): void
    {
        $parsed = app(WialonEfficiencyReportParser::class)->parse([
            'tables' => [[
                'index' => 0,
                'table' => [
                    'header' => ['Grouping', 'Engine hours', 'Начало', 'Конец', 'Пробег', 'Нач. положение', 'Кон. положение'],
                    'header_type' => ['', 'duration', 'time_begin', 'time_end', 'mileage', '', ''],
                    'rows' => 1,
                ],
                'rows' => [[
                    'uid' => 600595758,
                    'c' => ['110-FD-084', '381.37', '08:22:03', '23:59:55', '4.20 км', 'Xocavənd təlim mərkəzi', 'Füzuli Xocavənd avtomobil yolu'],
                    'r' => [
                        ['c' => ['2026-07-01', '8.86', '08:22:03', '17:54:13', '0.00 км', 'Xocavənd təlim mərkəzi', 'Xocavənd təlim mərkəzi']],
                        ['c' => ['2026-07-11', '16.75', '07:51:46', '2026-07-12 05:08:33', '1.86 км', 'Xocavənd təlim mərkəzi', 'Füzuli Xocavənd avtomobil yolu']],
                    ],
                ]],
            ]],
        ]);

        $this->assertSame(2, $parsed['rows_received']);
        $this->assertCount(2, $parsed['records']);
        $this->assertSame('600595758', $parsed['records'][0]['wialon_unit_id']);
        $this->assertSame('110-FD-084', $parsed['records'][0]['unit_name']);
        $this->assertSame('2026-07-01', $parsed['records'][0]['record_date']);
        $this->assertSame(8.86, $parsed['records'][0]['engine_hours_decimal']);
        $this->assertSame('2026-07-01 08:22:03', $parsed['records'][0]['started_at']?->format('Y-m-d H:i:s'));
        $this->assertSame('Xocavənd təlim mərkəzi', $parsed['records'][0]['final_location']);
        $this->assertSame('2026-07-11', $parsed['records'][1]['record_date']);
        $this->assertSame(16.75, $parsed['records'][1]['engine_hours_decimal']);
        $this->assertSame('Füzuli Xocavənd avtomobil yolu', $parsed['records'][1]['final_location']);
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

    public function test_forced_sync_limited_to_vehicle_types_preserves_other_types(): void
    {
        [$handler, $run, $task] = $this->handlerScenario($this->report('6001', '7,50', '4,25 km'));
        $project = Project::query()->where('name', 'Sync project')->firstOrFail();
        $loaderType = EquipmentType::query()->create(['name' => 'Loader']);
        $loader = Equipment::query()->create([
            'name' => 'Unit 7001',
            'wialon_unit_id' => '7001',
            'equipment_type_id' => $loaderType->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
        $this->fact($project, '7001', '2026-07-31', Equipment::OWNERSHIP_NWC, 'Loader', EfficiencyStatus::ONE_TO_SEVEN, 4);
        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-31',
            'equipment_id' => $loader->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 4,
            'distance_km' => 2,
            'utilization_percent' => 40,
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'success',
        ]);
        DailyUnitAggregate::query()->create([
            'date' => '2026-07-31',
            'unit_id' => '7001',
            'equipment_id' => $loader->id,
            'project_id' => $project->id,
            'equipment_type_id' => $loader->equipment_type_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'engine_hours' => 4,
            'mileage' => 2,
            'geofence_outside_hours' => 0,
        ]);
        EngineHoursReportUnitDay::query()->create([
            'stat_date' => '2026-07-31',
            'equipment_id' => $loader->id,
            'project_id' => $project->id,
            'equipment_type_id' => $loader->equipment_type_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'wialon_unit_id' => '7001',
            'unit_name' => $loader->name,
            'vehicle_type' => 'Loader',
            'engine_hours' => 4,
            'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
            'parse_status' => 'ok',
        ]);
        $run->forceFill([
            'options_json' => ['vehicle_types' => [FleetVehicleType::DUMP_TRUCK]],
        ])->save();

        $this->assertSame(2, $handler->execute($run->refresh(), $task));

        $this->assertSame(3, EfficiencyDailyFact::query()->count());
        $this->assertSame(1, EfficiencyDailyFact::query()->where('vehicle_type', 'Loader')->count());
        $this->assertSame(2, EfficiencyDailyFact::query()->where('vehicle_type', 'Dump Truck')->count());
        $this->assertSame(2, EquipmentDailyStat::query()->count());
        $this->assertSame(2, DailyUnitAggregate::query()->count());
        $this->assertSame(2, EngineHoursReportUnitDay::query()->count());
        $this->assertDatabaseHas('efficiency_daily_facts', [
            'wialon_unit_id' => '7001',
            'vehicle_type' => 'Loader',
            'engine_hours_decimal' => 4,
        ]);
    }

    public function test_sync_uses_actual_baku_interval_and_merges_split_wialon_date_rows(): void
    {
        $report = ['tables' => [[
            'index' => 0,
            'table' => [
                'header' => ['Grouping', 'Engine hours', 'Begin', 'End', 'Mileage'],
                'header_type' => ['', 'duration', 'time_begin', 'time_end', 'mileage'],
                'rows' => 1,
            ],
            'rows' => [[
                'uid' => 6001,
                'c' => ['Unit 6001', '1.13', '00:01:23', '08:07:19', '16.29 km'],
                'r' => [
                    ['c' => [
                        '2026-07-30',
                        '0.40',
                        ['t' => '00:01:23', 'v' => CarbonImmutable::parse('2026-07-31 00:01:23', 'Asia/Baku')->timestamp, 'u' => 6001],
                        ['t' => '00:25:23', 'v' => CarbonImmutable::parse('2026-07-31 00:25:23', 'Asia/Baku')->timestamp, 'u' => 6001],
                        '1.00 km',
                    ]],
                    ['c' => [
                        '2026-07-31',
                        '0.73',
                        ['t' => '07:23:24', 'v' => CarbonImmutable::parse('2026-07-31 07:23:24', 'Asia/Baku')->timestamp, 'u' => 6001],
                        ['t' => '08:07:19', 'v' => CarbonImmutable::parse('2026-07-31 08:07:19', 'Asia/Baku')->timestamp, 'u' => 6001],
                        '15.29 km',
                    ]],
                ],
            ]],
        ]]];
        [$handler, $run, $task] = $this->handlerScenario($report);

        $this->assertSame(2, $handler->execute($run, $task));

        $this->assertDatabaseHas('efficiency_daily_facts', [
            'business_date' => '2026-07-31',
            'wialon_unit_id' => '6001',
            'engine_hours_decimal' => 1.13,
            'engine_seconds' => 4068,
            'mileage_km' => 16.29,
            'efficiency_status' => EfficiencyStatus::ONE_TO_SEVEN,
        ]);
        $this->assertDatabaseHas('efficiency_daily_facts', [
            'business_date' => '2026-07-31',
            'wialon_unit_id' => '6002',
            'engine_seconds' => 0,
            'efficiency_status' => EfficiencyStatus::NO_DATA,
        ]);
        $this->assertSame('2026-07-31 00:01:23', EfficiencyDailyFact::query()
            ->where('wialon_unit_id', '6001')
            ->value('started_at')
            ?->format('Y-m-d H:i:s'));
        $this->assertSame(1.13, (float) EquipmentDailyStat::query()->where('equipment_id', Equipment::query()
            ->where('wialon_unit_id', '6001')
            ->value('id'))->value('worked_hours'));
        $this->assertSame(16.29, (float) DailyUnitAggregate::query()->where('unit_id', '6001')->value('mileage'));
    }

    public function test_forced_sync_updates_shared_dashboard_rows_when_equipment_project_changed(): void
    {
        [$handler, $run, $task] = $this->handlerScenario($this->report('6001', '7,50', '4,25 km'));
        $currentProject = Project::query()->where('name', 'Sync project')->firstOrFail();
        $oldProject = Project::query()->create(['name' => 'Old project', 'active' => true]);
        $equipment = Equipment::query()->where('wialon_unit_id', '6001')->firstOrFail();

        $dailyStat = EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-31',
            'equipment_id' => $equipment->id,
            'project_id' => $oldProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 1.25,
            'distance_km' => 2.5,
            'utilization_percent' => 12.5,
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'success',
        ]);
        $aggregate = DailyUnitAggregate::query()->create([
            'date' => '2026-07-31',
            'unit_id' => '6001',
            'equipment_id' => $equipment->id,
            'project_id' => $oldProject->id,
            'equipment_type_id' => $equipment->equipment_type_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'engine_hours' => 1.25,
            'mileage' => 2.5,
            'geofence_outside_hours' => 0,
        ]);
        $topWorkingRow = EngineHoursReportUnitDay::query()->create([
            'stat_date' => '2026-07-31',
            'equipment_id' => $equipment->id,
            'project_id' => $oldProject->id,
            'equipment_type_id' => $equipment->equipment_type_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'wialon_unit_id' => '6001',
            'unit_name' => $equipment->name,
            'vehicle_type' => 'Dump Truck',
            'engine_hours' => 1.25,
            'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
            'parse_status' => 'ok',
        ]);

        $handler->execute($run, $task);

        $this->assertSame(1, EquipmentDailyStat::query()->count());
        $this->assertSame(1, DailyUnitAggregate::query()->count());
        $this->assertSame(1, EngineHoursReportUnitDay::query()->count());
        $this->assertSame($dailyStat->id, EquipmentDailyStat::query()->value('id'));
        $this->assertSame($aggregate->id, DailyUnitAggregate::query()->value('id'));
        $this->assertSame($topWorkingRow->id, EngineHoursReportUnitDay::query()->value('id'));
        $this->assertDatabaseHas('equipment_daily_stats', [
            'stat_date' => '2026-07-31',
            'equipment_id' => $equipment->id,
            'project_id' => $currentProject->id,
            'worked_hours' => 7.5,
            'distance_km' => 4.25,
        ]);
        $this->assertDatabaseHas('daily_unit_aggregates', [
            'date' => '2026-07-31',
            'unit_id' => '6001',
            'project_id' => $currentProject->id,
            'engine_hours' => 7.5,
            'mileage' => 4.25,
        ]);
        $this->assertDatabaseHas('engine_hours_report_unit_days', [
            'stat_date' => '2026-07-31',
            'equipment_id' => $equipment->id,
            'project_id' => $currentProject->id,
            'engine_hours' => 7.5,
            'report_template_name' => 'Qrup report Engine hours (api)',
        ]);
    }

    public function test_monthly_source_sync_does_not_update_shared_dashboard_rows(): void
    {
        [$handler, $run, $task] = $this->handlerScenario($this->report('6001', '7,50', '4,25 km'));
        $project = Project::query()->where('name', 'Sync project')->firstOrFail();
        $equipment = Equipment::query()->where('wialon_unit_id', '6001')->firstOrFail();

        $run->forceFill([
            'options_json' => ['monthly_efficiency_source' => 'group_report'],
        ])->save();

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-31',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 1.25,
            'distance_km' => 2.5,
            'utilization_percent' => 12.5,
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'success',
        ]);

        $this->assertSame(2, $handler->execute($run->refresh(), $task));

        $this->assertSame(2, EfficiencyDailyFact::query()->count());
        $this->assertSame(1, EquipmentDailyStat::query()->count());
        $this->assertSame(1.25, (float) EquipmentDailyStat::query()->value('worked_hours'));
        $this->assertSame(0, DailyUnitAggregate::query()->count());
        $this->assertSame(0, EngineHoursReportUnitDay::query()->count());
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

    public function test_report_service_loads_group_date_subrows_for_location_tables(): void
    {
        config()->set('fleet.wialon.efficiency_report_resource_id', 601701680);
        config()->set('fleet.wialon.efficiency_report_template_id', 19);
        config()->set('fleet.wialon.efficiency_report_template_name', 'Qrup date report Engine hours (api)');

        $parentRow = [
            'uid' => 600595758,
            'c' => ['110-FD-084', '381.37', '08:22:03', '23:59:55', '4.20 km', 'Project A', 'Project B'],
        ];
        $childRows = [
            ['c' => ['2026-07-01', '8.86', '08:22:03', '17:54:13', '0.00 km', 'Project A', 'Project A']],
            ['c' => ['2026-07-11', '16.75', '07:51:46', '2026-07-12 05:08:33', '1.86 km', 'Project A', 'Project B']],
        ];

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('findReportTemplateByName')->once()->andReturn([
            'resource_id' => 601701680,
            'id' => 19,
            'type' => 'avl_unit_group',
        ]);
        $wialon->shouldReceive('cleanupReportResult')->twice();
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => ['tables' => [[
                'name' => 'Qrup date report Engine hours (api)',
                'header' => ['Grouping', 'Engine hours', 'Begin', 'End', 'Mileage', 'Initial location', 'Final location'],
                'header_type' => ['', 'duration', 'time_begin', 'time_end', 'mileage', '', ''],
                'rows' => 1,
            ]]],
        ]);
        $wialon->shouldReceive('selectReportResultRows')->twice()->andReturn([$parentRow], [$parentRow]);
        $wialon->shouldReceive('getReportResultSubrows')->once()->with(0, 0, 'test-session')->andReturn($childRows);

        $lock = Mockery::mock(WialonReportSessionLock::class);
        $lock->shouldReceive('run')->once()->andReturnUsing(fn ($callback) => $callback());
        $group = new ProjectWialonGroup(['wialon_group_id' => '9001']);

        $report = (new WialonEfficiencyReportService($wialon, $lock))->execute(
            $group,
            now('Asia/Baku')->startOfDay(),
            now('Asia/Baku')->endOfDay(),
            'test-session',
        );

        $this->assertSame($childRows, $report['tables'][0]['rows'][0]['r']);
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
