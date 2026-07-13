<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use App\Services\WialonService;
use Carbon\Carbon;
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
                'less_than_1' => 2,
                'from_1_to_7' => 1,
                'from_7_to_10' => 1,
                'overtime' => 1,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 1,
                'from_1_to_7' => 0,
                'from_7_to_10' => 1,
                'overtime' => 1,
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
                'less_than_1' => 0,
                'from_1_to_7' => 1,
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 0,
                'from_1_to_7' => 0,
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
        ], $result);
    }

    public function test_single_day_actual_work_hour_categories_are_loaded_from_wialon_report(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

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
            public function __construct()
            {
            }

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
                'less_than_1' => 1,
                'from_1_to_7' => 1,
                'from_7_to_10' => 1,
                'overtime' => 1,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 1,
                'from_1_to_7' => 0,
                'from_7_to_10' => 0,
                'overtime' => 0,
            ],
        ], $result);
    }

    public function test_date_range_actual_work_hour_categories_are_loaded_from_wialon_report(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

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

            public function __construct()
            {
            }

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
                'less_than_1' => 1,
                'from_1_to_7' => 1,
                'from_7_to_10' => 1,
                'overtime' => 1,
            ],
            Equipment::OWNERSHIP_ICARE => [
                'less_than_1' => 1,
                'from_1_to_7' => 0,
                'from_7_to_10' => 1,
                'overtime' => 0,
            ],
        ], $result);

        $this->assertCount(2, $wialon->calls);
        $this->assertSame(
            Carbon::parse('2026-07-01', config('app.timezone'))->startOfDay()->timestamp,
            $wialon->calls[0]['from']
        );
        $this->assertSame(
            Carbon::parse('2026-07-02', config('app.timezone'))->endOfDay()->timestamp,
            $wialon->calls[0]['to']
        );
        $this->assertSame(16777216, $wialon->calls[0]['intervalFlags']);
        $this->assertTrue($wialon->calls[0]['remoteExec']);
    }

    public function test_project_work_hour_cards_use_engine_hours_report_and_track_missing_data(): void
    {
        Cache::flush();

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Imported']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC zero');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC less');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC from one');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC seven');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC ten');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC overtime');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC missing');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE day');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE invalid');

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
            public function __construct()
            {
            }

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
            'date_to' => '2026-07-13',
        ]);

        $this->assertSame([
            'less_than_1' => 2,
            'from_1_to_7' => 1,
            'from_7_to_10' => 2,
            'overtime' => 1,
            'total' => 6,
            'missing_data' => 1,
        ], array_intersect_key($result[Equipment::OWNERSHIP_NWC][0], array_flip([
            'less_than_1',
            'from_1_to_7',
            'from_7_to_10',
            'overtime',
            'total',
            'missing_data',
        ])));

        $this->assertSame([
            'less_than_1' => 0,
            'from_1_to_7' => 0,
            'from_7_to_10' => 0,
            'overtime' => 1,
            'total' => 1,
            'missing_data' => 1,
        ], array_intersect_key($result[Equipment::OWNERSHIP_ICARE][0], array_flip([
            'less_than_1',
            'from_1_to_7',
            'from_7_to_10',
            'overtime',
            'total',
            'missing_data',
        ])));
    }

    public function test_average_metrics_by_ownership_use_engine_hours_and_mileage_from_wialon_report(): void
    {
        $project = Project::create(['name' => 'Yuxari Sirvan LOT1', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Truck']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC first');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC second');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC without row');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE first');

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

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

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
                                'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Mileage'],
                                'header_type' => ['', 'user_column', 'user_column', 'duration', 'mileage'],
                            ],
                            'rows' => (string) $objectId === '601701930'
                                ? [
                                    ['c' => ['NWC first', '', '', '10.00', '120.5 km']],
                                    ['c' => ['NWC second', '', '', '8.00', '79,5']],
                                ]
                                : [
                                    ['c' => ['ICARE first', '', '', '7.40', '55']],
                                ],
                        ],
                    ],
                ];
            }
        });

        $result = app(DashboardService::class)->getAverageMetricsByOwnership([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-11',
        ]);

        $this->assertSame(3, $result[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(6.0, $result[Equipment::OWNERSHIP_NWC]['avg_hours']);
        $this->assertSame(66.7, $result[Equipment::OWNERSHIP_NWC]['avg_mileage']);
        $this->assertSame(1, $result[Equipment::OWNERSHIP_ICARE]['count']);
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
            ]);
        }
    }
}
