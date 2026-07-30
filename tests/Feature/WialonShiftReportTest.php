<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\FleetEfficiencyService;
use App\Services\FleetShiftDailyStatsSyncService;
use App\Services\WialonService;
use App\Services\WialonShiftReportParser;
use App\Services\WialonShiftReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WialonShiftReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_daytime_status_boundaries_use_shift_hours_only(): void
    {
        $service = app(FleetEfficiencyService::class);
        $parser = app(WialonShiftReportParser::class);

        $this->assertNull($service->daytimeStatusForHours(null));
        $this->assertSame('less_than_1_hour', $service->daytimeStatusForHours(0));
        $this->assertSame('less_than_1_hour', $service->daytimeStatusForHours(0.5));
        $this->assertSame('less_than_1_hour', $service->daytimeStatusForHours(0.99));
        $this->assertSame('less_than_7_hours', $service->daytimeStatusForHours(1.0));
        $this->assertSame('less_than_7_hours', $service->daytimeStatusForHours(5.0));
        $this->assertSame('less_than_7_hours', $service->daytimeStatusForHours(6.99));
        $this->assertSame('between_7_and_10_hours', $service->daytimeStatusForHours(7.0));
        $this->assertSame('between_7_and_10_hours', $service->daytimeStatusForHours(10.0));
        $this->assertSame('over_10_hours', $service->daytimeStatusForHours(10.01));
        $this->assertSame('over_10_hours', $service->daytimeStatusForHours(11.0));
        $this->assertSame('between_7_and_10_hours', $service->efficiencyStatusForHours(10.0, 10.0));
        $this->assertSame('between_7_and_10_hours', $service->efficiencyStatusForHours(10.0, 12.0));
        $this->assertSame('less_than_7_hours', $service->efficiencyStatusForHours(6.0, 11.0));
        $this->assertSame('no_data', $service->efficiencyStatusForHours(0.0, 0.0));
        $this->assertSame('night_shift_only', $service->efficiencyStatusForHours(0.0, 9.11));
        $this->assertSame('no_data', $parser->daytimeStatus(0));
        $this->assertSame('less_than_1_hour', $parser->daytimeStatus(0.5));
    }

    public function test_parser_does_not_calculate_shift_hours_from_intervals(): void
    {
        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-19 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-19 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'name' => 'Shift intervals',
                    'header' => ['Unit', 'Date', 'Start', 'End', 'Duration'],
                    'rows' => 1,
                ],
                'rows' => [[
                    'unitId' => '6001',
                    'c' => ['Unit A', '2026-07-19', '', '', ''],
                    'r' => [
                        ['unitId' => '6001', 'c' => ['Unit A', '2026-07-19', '11:00:00', '16:00:00', '5:00:00']],
                        ['unitId' => '6001', 'c' => ['Unit A', '2026-07-19', '18:30:00', '20:00:00', '1:30:00']],
                        ['unitId' => '6001', 'c' => ['Unit A', '2026-07-19', '23:00:00', '01:00:00', '2:00:00']],
                    ],
                ]],
            ]],
        ]);

        $this->assertSame([], $parsed['records']);
    }

    public function test_parser_uses_daytime_and_overtime_columns_without_manual_boundary_split(): void
    {
        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-20 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-20 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'name' => 'Qrup report novbe 24 saat (api)',
                    'header' => ['Unit', 'Date', 'Daytime', 'Overtime', 'Total'],
                    'rows' => 1,
                ],
                'rows' => [[
                    'unitId' => '6009',
                    'c' => ['Unit Boundary', '2026-07-20', '10:00:00', '1:00:00', '11:00:00'],
                ]],
            ]],
        ]);

        $record = $parsed['records'][0];

        $this->assertSame(10.0, $record['daytime_hours']);
        $this->assertSame(1.0, $record['overtime_hours']);
        $this->assertSame(11.0, $record['total_hours']);
    }

    public function test_parser_uses_wialon_shift_columns_as_source_of_truth(): void
    {
        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-20 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-20 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'name' => 'Qrup report novbe 24 saat (api)',
                    'header' => ['Unit', 'Date', 'Daytime', 'Overtime', 'Total'],
                    'rows' => 1,
                ],
                'rows' => [[
                    'unitId' => '6010',
                    'c' => ['Unit Shift Columns', '2026-07-20', '0:30:00', '4:00:00', '4:30:00'],
                    'r' => [
                        ['unitId' => '6010', 'c' => ['Unit Shift Columns', '2026-07-20', '08:00:00', '17:00:00', '9:00:00']],
                    ],
                ]],
            ]],
        ]);

        $record = $parsed['records'][0];

        $this->assertSame(0.5, $record['daytime_hours']);
        $this->assertSame(4.0, $record['overtime_hours']);
        $this->assertSame(4.5, $record['total_hours']);
        $this->assertSame('direct_shift_columns', $record['reason']);
        $this->assertSame([], $record['source_intervals']);
    }

    public function test_parser_merges_wialon_grouped_shift_rows_as_source_of_truth(): void
    {
        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-01 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-01 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'name' => 'unit_group_engine_hours',
                    'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End'],
                    'rows' => 4,
                ],
                'rows' => [
                    [
                        'c' => ['(2026-06-30) Overtime', '', '', '7200', '', '', '', '', '', '', ''],
                        'r' => [
                            ['uid' => '7001', 'c' => ['Unit Grouped', '', 'XCMG', '7200', 'Loader', 'NWC', '2024', '0', '0 km', '20:00:00', '07:59:00']],
                        ],
                    ],
                    [
                        'c' => ['(2026-07-01) Daytime', '', '', '16:30:00', '', '', '', '', '', '', ''],
                        'r' => [
                            ['uid' => '7001', 'c' => ['Unit Grouped', '', 'XCMG', '6:00:00', 'Loader', 'NWC', '2024', '0', '0 km', '08:00:00', '17:59:00']],
                            ['uid' => '7002', 'c' => ['Unit Day Only', '', 'XCMG', '10:30:00', 'Loader', 'NWC', '2024', '0', '0 km', '08:00:00', '17:59:00']],
                        ],
                    ],
                    [
                        'c' => ['(2026-07-01) Overtime', '', '', '1:30:00', '', '', '', '', '', '', ''],
                        'r' => [
                            ['uid' => '7001', 'c' => ['Unit Grouped', '', 'XCMG', '1:30:00', 'Loader', 'NWC', '2024', '0', '0 km', '18:00:00', '19:30:00']],
                        ],
                    ],
                    [
                        'uid' => '9999',
                        'c' => ['Plain interval without shift label', '', 'XCMG', '4:00:00', 'Loader', 'NWC', '2024', '0', '0 km', '08:00:00', '12:00:00'],
                    ],
                ],
            ]],
        ]);

        $this->assertSame(2, count($parsed['records']));
        $this->assertSame(0, $parsed['unknown_rows']);

        $grouped = collect($parsed['records'])->firstWhere('wialon_unit_id', '7001');
        $this->assertSame('Unit Grouped', $grouped['unit_name']);
        $this->assertSame('2026-07-01', $grouped['statistic_date']);
        $this->assertSame(6.0, $grouped['daytime_hours']);
        $this->assertSame(3.5, $grouped['overtime_hours']);
        $this->assertSame(9.5, $grouped['total_hours']);
        $this->assertSame('grouped_shift_rows', $grouped['reason']);

        $dayOnly = collect($parsed['records'])->firstWhere('wialon_unit_id', '7002');
        $this->assertSame(10.5, $dayOnly['daytime_hours']);
        $this->assertSame(0.0, $dayOnly['overtime_hours']);
        $this->assertSame(10.5, $dayOnly['total_hours']);
        $this->assertSame('grouped_shift_rows', $dayOnly['reason']);
    }

    public function test_shift_report_nested_rows_are_loaded_only_to_configured_depth(): void
    {
        config(['fleet.wialon.shift_report_nested_depth' => 1]);

        $service = new class(app(WialonService::class), app(WialonShiftReportParser::class)) extends WialonShiftReportService
        {
            public array $requestedRows = [];

            public function loadNestedRows(string $sid, int $tableIndex, int $rowIndex): array
            {
                $this->requestedRows[] = $rowIndex;

                return [[
                    '_row_index' => $rowIndex + 100,
                    'c' => ['Nested row '.$rowIndex],
                ]];
            }
        };

        $rows = $service->withNestedRows('sid', 0, [[
            '_row_index' => 7,
            'c' => ['Parent row'],
        ]]);

        $this->assertSame([7], $service->requestedRows);
        $this->assertArrayHasKey('r', $rows[0]);
        $this->assertCount(1, $rows[0]['r']);
        $this->assertArrayNotHasKey('r', $rows[0]['r'][0]);
    }

    public function test_parser_uses_single_day_report_period_before_year_column(): void
    {
        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-20 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-20 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'name' => 'unit_group_engine_hours',
                    'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End'],
                    'rows' => 1,
                ],
                'rows' => [[
                    'uid' => '25631312',
                    'c' => [
                        '10-AD-163',
                        '',
                        'LIUGONG',
                        '0.04',
                        'Road grader',
                        'NWC',
                        '2022',
                        '0.03',
                        '0.00 km',
                        '13:24:47',
                        ['t' => '13:27:29', 'v' => 1784554049, 'u' => 25631312],
                    ],
                ]],
            ]],
        ]);

        $this->assertSame([], $parsed['records']);
    }

    public function test_shift_values_keep_daytime_categories_and_total_over_ten_indicator_independent(): void
    {
        $project = Project::query()->create(['name' => 'Shift Project', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701999',
            'name' => 'Shift Project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);
        $cases = [
            ['Unit daytime 9 overtime 4', '7001', 9.0, 4.0, FleetEfficiencyService::DAY_STATUS_BETWEEN_7_AND_10, true],
            ['Unit daytime 3 overtime 5', '7002', 3.0, 5.0, FleetEfficiencyService::DAY_STATUS_LESS_THAN_7, true],
            ['Unit daytime 0 overtime 4', '7003', 0.0, 4.0, FleetEfficiencyService::STATUS_NIGHT_SHIFT_ONLY, true],
            ['Unit daytime 8 overtime 0', '7004', 8.0, 0.0, FleetEfficiencyService::DAY_STATUS_BETWEEN_7_AND_10, false],
            ['Unit daytime 6 overtime 0', '7005', 6.0, 0.0, FleetEfficiencyService::DAY_STATUS_LESS_THAN_7, false],
            ['Unit daytime 10.5 overtime 0', '7006', 10.5, 0.0, FleetEfficiencyService::DAY_STATUS_OVER_10, false],
            ['Unit daytime 10 overtime 2', '7007', 10.0, 2.0, FleetEfficiencyService::DAY_STATUS_BETWEEN_7_AND_10, true],
        ];

        foreach ($cases as [$name, $wialonId]) {
            $this->equipment($name, $wialonId, $loader, $project);
        }

        app(FleetShiftDailyStatsSyncService::class)->syncGroup(
            $group,
            CarbonImmutable::parse('2026-07-20'),
            CarbonImmutable::parse('2026-07-20'),
            collect($cases)->map(fn (array $case): array => [
                'wialon_unit_id' => $case[1],
                'unit_name' => $case[0],
                'statistic_date' => '2026-07-20',
                'daytime_hours' => $case[2],
                'overtime_hours' => $case[3],
                'total_hours' => 99.0,
                'source_intervals' => [],
                'reason' => 'direct_shift_columns',
            ])->all(),
            ['resource_id' => 1, 'template_id' => 2]
        );

        foreach ($cases as [$name, $wialonId, $daytime, $overtime, $expectedStatus, $expectedOvertime]) {
            $stat = EquipmentDailyStat::query()
                ->whereHas('equipment', fn ($query) => $query->where('wialon_unit_id', $wialonId))
                ->firstOrFail();

            $this->assertSame($expectedStatus, $stat->day_status, $name);
            $this->assertSame($expectedOvertime, (bool) $stat->has_overtime, $name);
            $this->assertSame(number_format($daytime, 2, '.', ''), (string) $stat->daytime_hours, $name);
            $this->assertSame(number_format($overtime, 2, '.', ''), (string) $stat->overtime_hours, $name);
            $this->assertSame(number_format($daytime + $overtime, 2, '.', ''), (string) $stat->total_hours, $name);
        }

        $efficiency = app(FleetEfficiencyService::class);
        $summary = $efficiency->summaryForOwnership([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(0, $summary[FleetEfficiencyService::DAY_STATUS_LESS_THAN_1]);
        $this->assertSame(2, $summary[FleetEfficiencyService::DAY_STATUS_LESS_THAN_7]);
        $this->assertSame(3, $summary[FleetEfficiencyService::DAY_STATUS_BETWEEN_7_AND_10]);
        $this->assertSame(1, $summary[FleetEfficiencyService::STATUS_NIGHT_SHIFT_ONLY]);
        $this->assertSame(3, $summary[FleetEfficiencyService::DAY_STATUS_OVER_10]);
        $this->assertSame(4, $summary[FleetEfficiencyService::STATUS_OVERTIME]);
        $this->assertSame(0, $summary[FleetEfficiencyService::STATUS_NO_DATA]);
        $this->assertSame(7, $summary['total']);

        $overtimeRows = $efficiency->paginate([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'work_category' => FleetEfficiencyService::STATUS_OVERTIME,
            'per_page' => 20,
        ]);

        $this->assertSame(4, $overtimeRows->total());

        $nightShiftOnlyRows = $efficiency->paginate([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'work_category' => FleetEfficiencyService::STATUS_NIGHT_SHIFT_ONLY,
            'per_page' => 20,
        ]);

        $this->assertSame(1, $nightShiftOnlyRows->total());
        $this->assertSame('Unit daytime 0 overtime 4', $nightShiftOnlyRows->items()[0]['name']);

        $dayRows = $efficiency->paginate([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'work_category' => FleetEfficiencyService::DAY_STATUS_BETWEEN_7_AND_10,
            'per_page' => 20,
        ]);

        $this->assertSame(3, $dayRows->total());
        $this->assertEqualsCanonicalizing(
            ['Unit daytime 9 overtime 4', 'Unit daytime 8 overtime 0', 'Unit daytime 10 overtime 2'],
            collect($dayRows->items())->pluck('name')->all()
        );

        $overTenRows = $efficiency->paginate([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'work_category' => FleetEfficiencyService::DAY_STATUS_OVER_10,
            'per_page' => 20,
        ]);

        $this->assertSame(3, $overTenRows->total());
        $this->assertEqualsCanonicalizing(
            ['Unit daytime 9 overtime 4', 'Unit daytime 10.5 overtime 0', 'Unit daytime 10 overtime 2'],
            collect($overTenRows->items())->pluck('name')->all()
        );

        $overTenExcelRows = $efficiency->exportRows([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'work_category' => FleetEfficiencyService::DAY_STATUS_OVER_10,
        ]);

        $this->assertCount(3, $overTenExcelRows);
        $this->assertEqualsCanonicalizing(
            ['Unit daytime 9 overtime 4', 'Unit daytime 10.5 overtime 0', 'Unit daytime 10 overtime 2'],
            collect($overTenExcelRows)->pluck(2)->all()
        );

        $excelRows = $efficiency->exportRows([
            'from' => '2026-07-20',
            'to' => '2026-07-20',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'work_category' => FleetEfficiencyService::STATUS_OVERTIME,
        ]);

        $this->assertCount(4, $excelRows);
        $this->assertEqualsCanonicalizing(
            ['Unit daytime 0 overtime 4', 'Unit daytime 3 overtime 5', 'Unit daytime 9 overtime 4', 'Unit daytime 10 overtime 2'],
            collect($excelRows)->pluck(2)->all()
        );
    }

    public function test_shift_sync_is_idempotent_uses_allowed_types_and_preserves_unknown_values(): void
    {
        $project = Project::query()->create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $backhoe = EquipmentType::query()->create(['name' => 'Bakhoe Loader']);
        $bulldozer = EquipmentType::query()->create(['name' => 'Bulldozer']);
        $target = $this->equipment('Unit A', '6001', $backhoe, $project);
        $silent = $this->equipment('Silent Unit', '6002', $backhoe, $project);
        $bulldozerUnit = $this->equipment('Bulldozer Unit', '6003', $bulldozer, $project);

        $records = [[
            'wialon_unit_id' => '6001',
            'unit_name' => 'Unit A',
            'statistic_date' => '2026-07-19',
            'daytime_hours' => 5.0,
            'overtime_hours' => 1.5,
            'total_hours' => 6.5,
            'source_intervals' => [],
            'reason' => 'direct_shift_columns',
        ]];

        $sync = app(FleetShiftDailyStatsSyncService::class);
        $sync->syncGroup($group, CarbonImmutable::parse('2026-07-19'), CarbonImmutable::parse('2026-07-19'), $records, ['resource_id' => 1, 'template_id' => 2]);
        $sync->syncGroup($group, CarbonImmutable::parse('2026-07-19'), CarbonImmutable::parse('2026-07-19'), $records, ['resource_id' => 1, 'template_id' => 2]);

        $this->assertSame(3, EquipmentDailyStat::query()->count());

        $targetStat = EquipmentDailyStat::query()->where('equipment_id', $target->id)->firstOrFail();
        $this->assertSame('less_than_7_hours', $targetStat->day_status);
        $this->assertTrue((bool) $targetStat->has_overtime);
        $this->assertSame('6.50', (string) $targetStat->total_hours);

        $silentStat = EquipmentDailyStat::query()->where('equipment_id', $silent->id)->firstOrFail();
        $this->assertNull($silentStat->daytime_hours);
        $this->assertNull($silentStat->overtime_hours);
        $this->assertFalse((bool) $silentStat->data_available);

        $bulldozerStat = EquipmentDailyStat::query()->where('equipment_id', $bulldozerUnit->id)->firstOrFail();
        $this->assertNull($bulldozerStat->daytime_hours);
        $this->assertFalse((bool) $bulldozerStat->data_available);
    }

    private function equipment(string $name, string $wialonId, EquipmentType $type, Project $project): Equipment
    {
        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => $wialonId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701935',
            'active' => true,
        ]);
    }
}
