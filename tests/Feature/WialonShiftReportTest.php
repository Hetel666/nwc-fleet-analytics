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
        $this->assertSame('no_data', $service->daytimeStatusForHours(0));
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

        $this->assertSame(3, count($parsed['records']));
        $this->assertSame(0, $parsed['unknown_rows']);

        $previousOvertime = collect($parsed['records'])->firstWhere('statistic_date', '2026-06-30');
        $this->assertSame('Unit Grouped', $previousOvertime['unit_name']);
        $this->assertSame(0.0, $previousOvertime['daytime_hours']);
        $this->assertSame(2.0, $previousOvertime['overtime_hours']);
        $this->assertSame(2.0, $previousOvertime['total_hours']);

        $grouped = collect($parsed['records'])
            ->where('wialon_unit_id', '7001')
            ->firstWhere('statistic_date', '2026-07-01');
        $this->assertSame('Unit Grouped', $grouped['unit_name']);
        $this->assertSame('2026-07-01', $grouped['statistic_date']);
        $this->assertSame(6.0, $grouped['daytime_hours']);
        $this->assertSame(1.5, $grouped['overtime_hours']);
        $this->assertSame(7.5, $grouped['total_hours']);
        $this->assertSame('grouped_shift_rows', $grouped['reason']);

        $dayOnly = collect($parsed['records'])->firstWhere('wialon_unit_id', '7002');
        $this->assertSame(10.5, $dayOnly['daytime_hours']);
        $this->assertSame(0.0, $dayOnly['overtime_hours']);
        $this->assertSame(10.5, $dayOnly['total_hours']);
        $this->assertSame('grouped_shift_rows', $dayOnly['reason']);
    }

    public function test_parser_uses_two_shift_tables_for_the_report_date(): void
    {
        $headers = ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End'];

        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-29 23:59:59', 'Asia/Baku'),
            'tables' => [
                [
                    'index' => 0,
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'header' => $headers,
                        'rows' => 1,
                    ],
                    'rows' => [[
                        'uid' => '600720325',
                        'c' => ['10-AF-171', 'CLG6616E', 'LIUGONG', '0.06', 'Road roller', 'NWC', '2024', '0.00', '0.45 km', '08:21:29', '08:25:37'],
                    ]],
                ],
                [
                    'index' => 1,
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'header' => $headers,
                        'rows' => 1,
                    ],
                    'rows' => [[
                        'uid' => '600720325',
                        'c' => ['10-AF-171', 'CLG6616E', 'LIUGONG', '0.44', 'Road roller', 'NWC', '2024', '0.28', '1.63 km', '06:23:38', '06:53:34'],
                    ]],
                ],
            ],
        ]);

        $currentDay = collect($parsed['records'])
            ->where('wialon_unit_id', '600720325')
            ->firstWhere('statistic_date', '2026-07-29');

        $this->assertSame('10-AF-171', $currentDay['unit_name']);
        $this->assertSame(0.06, $currentDay['daytime_hours']);
        $this->assertSame(0.44, $currentDay['overtime_hours']);
        $this->assertSame(0.5, $currentDay['total_hours']);

        $previousDay = collect($parsed['records'])
            ->where('wialon_unit_id', '600720325')
            ->firstWhere('statistic_date', '2026-07-28');

        $this->assertNull($previousDay);
    }

    public function test_parser_combines_independent_daytime_and_overtime_reports_by_unit_and_date(): void
    {
        $date = CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku');
        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => $date,
            'to' => $date->endOfDay(),
            'tables' => [
                [
                    'index' => 0,
                    '_source_shift' => 'daytime',
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'header' => ['Grouping', 'Engine hours'],
                        'rows' => 1,
                    ],
                    'rows' => [
                        ['uid' => '7001', 'c' => ['Unit both shifts', '8.00']],
                    ],
                ],
                [
                    'index' => 1,
                    '_source_shift' => 'overtime',
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'header' => ['Grouping', 'Engine hours'],
                        'rows' => 2,
                    ],
                    'rows' => [
                        ['uid' => '7001', 'c' => ['Unit both shifts', '3.00']],
                        ['uid' => '7002', 'c' => ['Unit night only', '12.00']],
                    ],
                ],
            ],
        ]);

        $records = collect($parsed['records'])->keyBy('wialon_unit_id');

        $this->assertSame(8.0, $records['7001']['daytime_hours']);
        $this->assertSame(3.0, $records['7001']['overtime_hours']);
        $this->assertSame(11.0, $records['7001']['total_hours']);
        $this->assertSame(0.0, $records['7002']['daytime_hours']);
        $this->assertSame(12.0, $records['7002']['overtime_hours']);
        $this->assertSame(12.0, $records['7002']['total_hours']);
    }

    public function test_efficiency_report_sources_are_configured_independently(): void
    {
        config([
            'fleet.wialon.shift_daytime_report_resource_id' => 601701680,
            'fleet.wialon.shift_daytime_report_template_id' => 21,
            'fleet.wialon.shift_daytime_report_template_name' => 'Qrup report daytime (api)',
            'fleet.wialon.shift_overtime_report_resource_id' => 601701680,
            'fleet.wialon.shift_overtime_report_template_id' => 22,
            'fleet.wialon.shift_overtime_report_template_name' => 'Qrup report overtime (api)',
        ]);

        $service = app(WialonShiftReportService::class);

        $this->assertSame(21, $service->settingsFor('daytime')['template_id']);
        $this->assertSame('Qrup report daytime (api)', $service->settingsFor('daytime')['template_name']);
        $this->assertSame(22, $service->settingsFor('overtime')['template_id']);
        $this->assertSame('Qrup report overtime (api)', $service->settingsFor('overtime')['template_name']);
    }

    public function test_report_service_executes_engine_hours_template_for_three_shift_windows(): void
    {
        config([
            'fleet.wialon.shift_engine_hours_report_resource_id' => 601701680,
            'fleet.wialon.shift_engine_hours_report_template_id' => 31,
            'fleet.wialon.shift_engine_hours_report_template_name' => 'Qrup report Engine hours (api)',
        ]);

        $wialon = new class extends WialonService
        {
            public array $templateCalls = [];

            public array $windows = [];

            private array $rows = [];

            public function __construct() {}

            public function executeReport(
                int|string $resourceId,
                int|string $templateId,
                int|string $objectId,
                int $from,
                int $to,
                int $intervalFlags = 0,
                ?string $sid = null,
                bool $remoteExec = false,
                ?int $requestTimeout = null
            ): array {
                $this->templateCalls[] = (int) $templateId;
                $this->windows[] = [
                    CarbonImmutable::createFromTimestamp($from, 'Asia/Baku')->format('H:i:s'),
                    CarbonImmutable::createFromTimestamp($to, 'Asia/Baku')->format('H:i:s'),
                ];
                $this->rows = match (CarbonImmutable::createFromTimestamp($from, 'Asia/Baku')->format('H:i:s')) {
                    '00:00:00' => [['uid' => '7002', 'c' => ['Unit night only', '2.00']]],
                    '08:00:00' => [['uid' => '7001', 'c' => ['Unit both shifts', '8.00']]],
                    default => [
                        ['uid' => '7001', 'c' => ['Unit both shifts', '3.00']],
                        ['uid' => '7002', 'c' => ['Unit night only', '10.00']],
                    ],
                };

                return [
                    'reportResult' => [
                        'tables' => [[
                            'name' => 'unit_group_engine_hours',
                            'header' => ['Grouping', 'Engine hours'],
                            'rows' => count($this->rows),
                        ]],
                    ],
                ];
            }

            public function selectReportResultRows(int $tableIndex, array $config, ?string $sid = null): array
            {
                return ($config['type'] ?? null) === 'range' ? $this->rows : [];
            }

            public function cleanupReportResult(string $sid): void {}
        };

        $service = new WialonShiftReportService($wialon, app(WialonShiftReportParser::class));
        $date = CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku');
        $report = $service->executeForGroupWithSession('601701915', $date, $date->endOfDay(), 'sid');
        $records = collect(app(WialonShiftReportParser::class)->parse($report)['records'])->keyBy('wialon_unit_id');

        $this->assertSame([31, 31, 31], $wialon->templateCalls);
        $this->assertSame([
            ['00:00:00', '07:59:59'],
            ['08:00:00', '17:59:59'],
            ['18:00:00', '23:59:59'],
        ], $wialon->windows);
        $this->assertSame('Qrup report Engine hours (api)', $report['template_name']);
        $this->assertSame(8.0, $records['7001']['daytime_hours']);
        $this->assertSame(3.0, $records['7001']['overtime_hours']);
        $this->assertSame(0.0, $records['7002']['daytime_hours']);
        $this->assertSame(12.0, $records['7002']['overtime_hours']);
    }

    public function test_parser_prefers_wialon_timestamp_value_when_classifying_shift_table_rows(): void
    {
        $headers = ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End'];

        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-29 23:59:59', 'Asia/Baku'),
            'tables' => [
                [
                    'index' => 0,
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'label' => 'Engine hours daytime',
                        'header' => $headers,
                        'rows' => 1,
                    ],
                    'rows' => [],
                ],
                [
                    'index' => 1,
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'label' => 'Engine hours overtime',
                        'header' => $headers,
                        'rows' => 1,
                    ],
                    'rows' => [[
                        'uid' => '600720824',
                        'c' => [
                            '110-FD-310',
                            '330GC',
                            'CAT',
                            '3.12',
                            'Excavator',
                            'NWC',
                            '2024',
                            '3.05',
                            '0.17 km',
                            [
                                't' => '2026-07-29 04:55:12',
                                'v' => CarbonImmutable::parse('2026-07-29 08:55:12', 'Asia/Baku')->timestamp,
                            ],
                            [
                                't' => '2026-07-29 08:02:38',
                                'v' => CarbonImmutable::parse('2026-07-29 12:02:38', 'Asia/Baku')->timestamp,
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $row = collect($parsed['records'])
            ->where('wialon_unit_id', '600720824')
            ->firstWhere('statistic_date', '2026-07-29');

        $this->assertSame('110-FD-310', $row['unit_name']);
        $this->assertSame(3.12, $row['daytime_hours']);
        $this->assertSame(0.0, $row['overtime_hours']);
        $this->assertSame(3.12, $row['total_hours']);
    }

    public function test_parser_uses_row_unix_timestamps_when_group_report_cell_text_is_utc(): void
    {
        $headers = ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End'];

        $parsed = app(WialonShiftReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-29 23:59:59', 'Asia/Baku'),
            'tables' => [
                [
                    'index' => 0,
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'label' => 'Engine hours daytime',
                        'header' => $headers,
                        'rows' => 1,
                    ],
                    'rows' => [[
                        'uid' => '600261257',
                        't1' => CarbonImmutable::parse('2026-07-29 12:20:33', 'Asia/Baku')->timestamp,
                        't2' => CarbonImmutable::parse('2026-07-29 17:36:02', 'Asia/Baku')->timestamp,
                        'c' => ['10-AD-725', 'B160', 'LIUGONG', '0.85', 'Bulldozer', 'NWC', '2023', '0.52', '2.28 km', '2026-07-29 08:20:33', '2026-07-29 13:36:02'],
                    ]],
                ],
                [
                    'index' => 1,
                    'table' => [
                        'name' => 'unit_group_engine_hours',
                        'label' => 'Engine hours overtime',
                        'header' => $headers,
                        'rows' => 1,
                    ],
                    'rows' => [[
                        'uid' => '600261257',
                        't1' => CarbonImmutable::parse('2026-07-29 08:18:06', 'Asia/Baku')->timestamp,
                        't2' => CarbonImmutable::parse('2026-07-29 11:57:37', 'Asia/Baku')->timestamp,
                        'c' => ['10-AD-725', 'B160', 'LIUGONG', '1.26', 'Bulldozer', 'NWC', '2023', '0.18', '1.76 km', '2026-07-29 04:18:06', '2026-07-29 07:57:37'],
                    ]],
                ],
            ],
        ]);

        $row = collect($parsed['records'])
            ->where('wialon_unit_id', '600261257')
            ->firstWhere('statistic_date', '2026-07-29');

        $this->assertSame('10-AD-725', $row['unit_name']);
        $this->assertSame(2.11, $row['daytime_hours']);
        $this->assertSame(0.0, $row['overtime_hours']);
        $this->assertSame(2.11, $row['total_hours']);
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
        $this->assertSame(3, $summary[FleetEfficiencyService::STATUS_OVERTIME]);
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

        $this->assertSame(3, $overtimeRows->total());

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

        $this->assertCount(3, $excelRows);
        $this->assertEqualsCanonicalizing(
            ['Unit daytime 3 overtime 5', 'Unit daytime 9 overtime 4', 'Unit daytime 10 overtime 2'],
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
