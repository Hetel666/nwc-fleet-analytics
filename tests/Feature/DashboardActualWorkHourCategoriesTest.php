<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use App\Services\FleetEfficiencyService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardActualWorkHourCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_work_hour_categories_use_average_daily_values_and_count_each_unit_once(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $nwcLess = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC less');
        $nwcWithoutStats = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC no stats');
        $nwcMiddle = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC middle');
        $nwcRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC regular');
        $nwcOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $icareLess = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE less');
        $icareRegular = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE regular');
        $icareOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE overtime');

        $this->stats($project, $nwcLess, [0.5, 0.5]);
        $this->stats($project, $nwcMiddle, [4, 6]);
        $this->stats($project, $nwcRegular, [7, 10]);
        $this->stats($project, $nwcOvertime, [11, 13]);
        $this->stats($project, $icareLess, [0, 0.5]);
        $this->stats($project, $icareRegular, [7, 7]);
        $this->stats($project, $icareOvertime, [12, 12]);

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ]);

        $this->assertSame([
            Equipment::OWNERSHIP_NWC => [
                'less_than_1_hour' => 1,
                'less_than_7_hours' => 1,
                'between_7_and_10_hours' => 1,
                'over_10_hours' => 1,
                'overtime' => 0,
                'no_data' => 1,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1_hour' => 1,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 1,
                'over_10_hours' => 1,
                'overtime' => 0,
                'no_data' => 1,
            ],
        ], $result);
    }

    public function test_actual_work_hour_categories_respect_equipment_type_and_ownership_filters(): void
    {
        $project = Project::create(['name' => 'Fuzuli Agdam yol', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $truck = EquipmentType::create(['name' => 'Truck']);

        $selected = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'Selected');
        $otherType = $this->equipment($project, $truck, Equipment::OWNERSHIP_NWC, 'Other type');
        $otherOwnership = $this->equipment($project, $excavator, Equipment::OWNERSHIP_ICARE, 'Other ownership');

        $this->stats($project, $selected, [5]);
        $this->stats($project, $otherType, [12]);
        $this->stats($project, $otherOwnership, [5]);

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'equipment_type_id' => $excavator->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame([
            Equipment::OWNERSHIP_NWC => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 1,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 0,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 0,
            ],
        ], $result);
    }

    public function test_single_day_actual_work_hour_categories_are_loaded_from_wialon_report(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC no report row');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC middle');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC regular');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE less');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701936',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        config(['fleet.wialon.actual_work_report_template_id' => 9]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct() {}

            public function getReportTablesRows(
                int|string $resourceId,
                int|string $templateId,
                int|string $objectId,
                int $from,
                int $to,
                int $chunkSize = 500,
                int $intervalFlags = 0,
                bool $remoteExec = false,
                ?int $requestTimeout = null
            ): array {
                return [
                    'tables' => [
                        [
                            'table' => [
                                'label' => 'Engine hours',
                                'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours'],
                                'header_type' => ['', 'user_column', 'user_column', 'duration'],
                            ],
                            'rows' => (string) $objectId === '601701935'
                                ? [
                                    ['c' => ['NWC middle', '', '', '5.25']],
                                    ['c' => ['NWC regular', '', '', '8.50']],
                                    ['c' => ['NWC overtime', '', '', '11.00']],
                                ]
                                : [
                                    ['c' => ['ICARE less', '', '', '0.50']],
                                ],
                        ],
                    ],
                ];
            }
        });

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'date_from' => '2026-07-09',
            'date_to' => '2026-07-09',
        ]);

        $this->assertSame([
            Equipment::OWNERSHIP_NWC => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 4,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 1,
            ],
        ], $result);
    }

    public function test_date_range_actual_work_hour_categories_are_loaded_from_wialon_report(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC no report row');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC middle');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC regular');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE less');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE regular');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701936',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        config(['fleet.wialon.actual_work_report_template_id' => 9]);

        $wialon = new class extends WialonService
        {
            public array $calls = [];

            public function __construct() {}

            public function getReportTablesRows(
                int|string $resourceId,
                int|string $templateId,
                int|string $objectId,
                int $from,
                int $to,
                int $chunkSize = 500,
                int $intervalFlags = 0,
                bool $remoteExec = false,
                ?int $requestTimeout = null
            ): array {
                $this->calls[] = compact('objectId', 'from', 'to', 'intervalFlags', 'remoteExec');

                return [
                    'tables' => [
                        [
                            'table' => [
                                'label' => 'Engine hours',
                                'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours'],
                                'header_type' => ['', 'user_column', 'user_column', 'duration'],
                            ],
                            'rows' => (string) $objectId === '601701935'
                                ? [
                                    ['c' => ['NWC middle', '', '', '10.00']],
                                    ['c' => ['NWC regular', '', '', '20.00']],
                                    ['c' => ['NWC overtime', '', '', '22.00']],
                                ]
                                : [
                                    ['c' => ['ICARE less', '', '', '1.50']],
                                    ['c' => ['ICARE regular', '', '', '14.00']],
                                ],
                        ],
                    ],
                ];
            }
        };

        $this->app->instance(WialonService::class, $wialon);

        $result = app(DashboardService::class)->getActualWorkHourCategories([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ]);

        $this->assertSame([
            Equipment::OWNERSHIP_NWC => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 4,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1_hour' => 0,
                'less_than_7_hours' => 0,
                'between_7_and_10_hours' => 0,
                'over_10_hours' => 0,
                'overtime' => 0,
                'no_data' => 2,
            ],
        ], $result);

        $this->assertCount(0, $wialon->calls);
    }

    public function test_project_work_hour_cards_use_stored_daily_stats_and_track_missing_data(): void
    {
        Cache::flush();

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $nwcZero = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC zero');
        $nwcLess = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC less');
        $nwcFromOne = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC from one');
        $nwcSeven = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC seven');
        $nwcTen = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC ten');
        $nwcOvertime = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC missing');
        $icareDay = $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE day');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE invalid');

        $dailyStat = function (Equipment $equipment, float $hours, ?float $overtimeHours = 0.0) use ($project): void {
            $dataAvailable = $overtimeHours !== null;
            EquipmentDailyStat::create([
                'stat_date' => '2026-07-01',
                'equipment_id' => $equipment->id,
                'project_id' => $project->id,
                'ownership_type' => $equipment->ownership_type,
                'worked_hours' => $hours,
                'daytime_hours' => $hours,
                'overtime_hours' => $overtimeHours,
                'total_hours' => $overtimeHours === null ? null : $hours + $overtimeHours,
                'day_status' => app(FleetEfficiencyService::class)->efficiencyStatusForHours(
                    $hours,
                    $overtimeHours === null ? null : $hours + $overtimeHours
                ),
                'has_overtime' => $overtimeHours === null ? null : $overtimeHours > 0,
                'data_available' => $dataAvailable,
                'daytime_data_available' => true,
                'overtime_data_available' => $dataAvailable,
                'distance_km' => 0,
                'calculation_source' => 'wialon_shift_report',
                'calculation_status' => $dataAvailable ? 'ok' : 'shift_unknown',
            ]);
        };

        $dailyStat($nwcZero, 0);
        $dailyStat($nwcLess, 0.99);
        $dailyStat($nwcFromOne, 1);
        $dailyStat($nwcSeven, 7);
        $dailyStat($nwcTen, 10);
        $dailyStat($nwcOvertime, 10.01, 0.5);
        $dailyStat($icareDay, 26.5, 2.0);

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701936',
            'name' => 'LOT3 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $wialon = new class extends WialonService
        {
            public function __construct() {}

            public function getReportTablesRows(
                int|string $resourceId,
                int|string $templateId,
                int|string $objectId,
                int $from,
                int $to,
                int $chunkSize = 500,
                int $intervalFlags = 0,
                bool $remoteExec = false,
                ?int $requestTimeout = null
            ): array {
                return [
                    'tables' => [
                        [
                            'table' => [
                                'label' => 'Engine hours',
                                'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours'],
                                'header_type' => ['', 'user_column', 'user_column', 'duration'],
                            ],
                            'rows' => (string) $objectId === '601701935'
                                ? [
                                    ['c' => ['NWC zero', '', '', '00:00:00']],
                                    ['c' => ['NWC less', '', '', '00:59:59']],
                                    ['c' => ['NWC from one', '', '', '01:00:00']],
                                    ['c' => ['NWC seven', '', '', '07:00:00']],
                                    ['c' => ['NWC ten', '', '', '10:00:00']],
                                    ['c' => ['NWC overtime', '', '', '10:00:01']],
                                ]
                                : [
                                    ['c' => ['ICARE day', '', '', '1 day 02:30:00']],
                                    ['c' => ['ICARE invalid', '', '', 'invalid']],
                                ],
                        ],
                    ],
                ];
            }
        };

        $this->app->instance(WialonService::class, $wialon);

        $result = app(DashboardService::class)->getProjectActualWorkHourCategoriesByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame([
            'less_than_1_hour' => 1,
            'less_than_7_hours' => 1,
            'between_7_and_10_hours' => 2,
            'over_10_hours' => 1,
            'overtime' => 1,
            'no_data' => 2,
            'total' => 7,
            'missing_data' => 2,
        ], array_intersect_key($result[Equipment::OWNERSHIP_NWC][0], array_flip([
            'less_than_1_hour',
            'less_than_7_hours',
            'between_7_and_10_hours',
            'over_10_hours',
            'overtime',
            'no_data',
            'total',
            'missing_data',
        ])));

        $this->assertSame([
            'less_than_1_hour' => 0,
            'less_than_7_hours' => 0,
            'between_7_and_10_hours' => 0,
            'over_10_hours' => 1,
            'overtime' => 1,
            'no_data' => 1,
            'total' => 2,
            'missing_data' => 1,
        ], array_intersect_key($result[Equipment::OWNERSHIP_ICARE][0], array_flip([
            'less_than_1_hour',
            'less_than_7_hours',
            'between_7_and_10_hours',
            'over_10_hours',
            'overtime',
            'no_data',
            'total',
            'missing_data',
        ])));
    }

    public function test_efficiency_export_uses_unique_equipment_total_for_overlapping_indicators(): void
    {
        Cache::flush();

        $project = Project::create(['name' => 'Efficiency Project', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        $working = $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'Working unit');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'Missing unit');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701930',
            'name' => 'Efficiency Project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        EquipmentDailyStat::create([
            'stat_date' => '2026-07-28',
            'equipment_id' => $working->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 11,
            'daytime_hours' => 8,
            'overtime_hours' => 3,
            'total_hours' => 11,
            'day_status' => FleetEfficiencyService::DAY_STATUS_BETWEEN_7_AND_10,
            'has_overtime' => true,
            'data_available' => true,
            'daytime_data_available' => true,
            'overtime_data_available' => true,
            'distance_km' => 0,
            'calculation_source' => 'wialon_shift_report',
            'calculation_status' => 'ok',
        ]);

        $export = app(DashboardService::class)->getDashboardExport([
            'project_id' => $project->id,
            'date_from' => '2026-07-28',
            'date_to' => '2026-07-28',
        ], 'actual-work-hours-nwc');
        $summaryRows = $export['sections'][0]['rows'];

        $this->assertSame([0, 0, 1, 0, 1, 2, 1, 1], array_column($summaryRows, 1));
        $this->assertSame(['Status', 'Say'], $export['sections'][0]['columns']);
        $this->assertCount(2, $export['sections'][1]['rows']);
    }

    public function test_average_metrics_by_ownership_use_prepared_engine_hours_and_mileage_stats(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT1', 'active' => true]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $dumpTruck = EquipmentType::create(['name' => 'Dump Truck']);
        $pickup = EquipmentType::create(['name' => 'Pickup']);

        $nwcExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC excavator');
        $nwcZeroExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC zero excavator');
        $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC invalid excavator');
        $nwcDump = $this->equipment($project, $dumpTruck, Equipment::OWNERSHIP_NWC, 'NWC dump');
        $this->equipment($project, $pickup, Equipment::OWNERSHIP_NWC, 'NWC pickup');
        $icareExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_ICARE, 'ICARE excavator');
        $icareDump = $this->equipment($project, $dumpTruck, Equipment::OWNERSHIP_ICARE, 'ICARE dump');
        $this->equipment($project, $dumpTruck, Equipment::OWNERSHIP_ICARE, 'ICARE invalid dump');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701930',
            'name' => 'LOT1 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701933',
            'name' => 'LOT1 ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);

        $this->metricStat($project, $nwcExcavator, '2026-07-01', 10.0, 12.0);
        $this->metricStat($project, $nwcZeroExcavator, '2026-07-01', 0.0, 5.0);
        $this->metricStat($project, $nwcDump, '2026-07-01', 99.0, 120.5);
        $this->metricStat($project, $icareExcavator, '2026-07-01', 7.4, 1.0);
        $this->metricStat($project, $icareDump, '2026-07-01', 8.0, 55.0);

        $this->mock(WialonService::class, function ($mock): void {
            $mock->shouldReceive('getReportTablesRows')->never();
        });

        $result = app(DashboardService::class)->getAverageMetricsByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-11',
        ]);

        $this->assertSame(5, $result[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(2, $result[Equipment::OWNERSHIP_NWC]['engine_hours_equipment_count']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_NWC]['mileage_equipment_count']);
        $this->assertSame(5.0, $result[Equipment::OWNERSHIP_NWC]['avg_hours']);
        $this->assertSame(120.5, $result[Equipment::OWNERSHIP_NWC]['avg_mileage']);
        $this->assertSame(3, $result[Equipment::OWNERSHIP_ICARE]['count']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_ICARE]['engine_hours_equipment_count']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_ICARE]['mileage_equipment_count']);
        $this->assertSame(7.4, $result[Equipment::OWNERSHIP_ICARE]['avg_hours']);
        $this->assertSame(55.0, $result[Equipment::OWNERSHIP_ICARE]['avg_mileage']);
    }

    private function equipment(Project $project, EquipmentType $type, string $ownershipType, string $name): Equipment
    {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownershipType,
            'matched_wialon_group_id' => $ownershipType === Equipment::OWNERSHIP_ICARE ? '601701933' : '601701930',
            'active' => true,
        ]);
    }

    /**
     * @param  list<float|int>  $hours
     */
    private function stats(Project $project, Equipment $equipment, array $hours): void
    {
        foreach ($hours as $index => $workedHours) {
            EquipmentDailyStat::create([
                'stat_date' => '2026-07-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'equipment_id' => $equipment->id,
                'project_id' => $project->id,
                'ownership_type' => $equipment->ownership_type,
                'worked_hours' => $workedHours,
                'daytime_hours' => $workedHours,
                'overtime_hours' => 0,
                'total_hours' => $workedHours,
                'day_status' => app(FleetEfficiencyService::class)->efficiencyStatusForHours((float) $workedHours, (float) $workedHours),
                'has_overtime' => false,
                'data_available' => true,
                'daytime_data_available' => true,
                'overtime_data_available' => true,
                'calculation_source' => 'wialon_shift_report',
                'calculation_status' => 'ok',
            ]);
        }
    }

    private function metricStat(Project $project, Equipment $equipment, string $date, float $workedHours, float $distanceKm): void
    {
        EquipmentDailyStat::create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $workedHours,
            'distance_km' => $distanceKm,
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'ok',
        ]);
    }
}
