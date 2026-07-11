<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGeofenceOutsideReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_geofence_outside_rows_are_merged_from_engine_and_geofence_report_tables(): void
    {
        config([
            'fleet.wialon.geofence_outside_report_resource_id' => 601701680,
            'fleet.wialon.geofence_outside_report_template_id' => 12,
        ]);

        $project = Project::create(['name' => 'Yuxari Sirvan LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC A');
        $this->equipment($project, $type, Equipment::OWNERSHIP_NWC, 'NWC B');
        $this->equipment($project, $type, Equipment::OWNERSHIP_ICARE, 'ICARE A');

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '602',
            'name' => 'LOT3 ICARE',
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
                $isNwc = (string) $objectId === '601';

                return [
                    'tables' => [
                        [
                            'table' => [
                                'label' => 'Engine hours',
                                'header' => ['Grouping', 'Vendor', 'Custom column', 'Engine hours'],
                                'header_type' => ['', '', '', 'duration'],
                            ],
                            'rows' => $isNwc
                                ? [
                                    ['c' => ['NWC A', 'NWC', '', '10:00:00']],
                                    ['c' => ['NWC B', 'NWC', '', '5.5']],
                                ]
                                : [
                                    ['c' => ['ICARE A', 'ICARE', '', '4']],
                                ],
                        ],
                        [
                            'table' => [
                                'label' => 'Geofence',
                                'header' => ['Grouping', 'Name', 'Duration of stay'],
                            ],
                            'rows' => $isNwc
                                ? [
                                    ['c' => ['NWC A', 'Zone 1', '2:00:00']],
                                    ['c' => ['NWC A', 'Zone 2', '1.5']],
                                    ['c' => ['NWC B', 'Zone 1', '6']],
                                ]
                                : [
                                    ['c' => ['ICARE A', 'Zone 1', '1']],
                                ],
                        ],
                    ],
                ];
            }
        });

        $rows = app(DashboardService::class)->getGeofenceOutsideRows([
            'project_id' => $project->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-08',
        ], null);

        $this->assertSame([
            [
                'grouping' => 'NWC A',
                'vendor' => 'NWC',
                'outside_hours' => 6.5,
            ],
            [
                'grouping' => 'ICARE A',
                'vendor' => 'ICARE',
                'outside_hours' => 3.0,
            ],
            [
                'grouping' => 'NWC B',
                'vendor' => 'NWC',
                'outside_hours' => 0.0,
            ],
        ], $rows);
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
}
