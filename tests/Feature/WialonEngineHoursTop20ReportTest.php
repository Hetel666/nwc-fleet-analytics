<?php

namespace Tests\Feature;

use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\WialonReportSyncItem;
use App\Services\EngineHoursTop20SyncService;
use App\Services\WialonEngineHoursReportParser;
use App\Services\WialonEngineHoursReportService;
use App\Services\WialonSessionManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WialonEngineHoursTop20ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_finds_engine_hours_column_and_skips_parent_rows(): void
    {
        $parsed = app(WialonEngineHoursReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-19 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-19 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'label' => 'Engine hours',
                    'header' => ['Grouping', 'Vendor', 'Type', 'Engine hours', 'Mileage'],
                    'header_type' => ['', 'custom', 'custom', 'duration', 'mileage'],
                    'rows' => 2,
                ],
                'rows' => [[
                    'c' => ['Parent', '', '', '24:00:00', ''],
                    'r' => [
                        ['uid' => '7001', 'c' => ['10-AF-098', 'NWC', 'Loader', '8:24:00', '0 km']],
                    ],
                ]],
            ]],
        ]);

        $this->assertCount(1, $parsed['records']);
        $this->assertSame('7001', $parsed['records'][0]['wialon_unit_id']);
        $this->assertSame('10-AF-098', $parsed['records'][0]['unit_name']);
        $this->assertSame(8.4, $parsed['records'][0]['engine_hours']);
        $this->assertSame(3, $parsed['records'][0]['engine_hours_column_index']);
    }

    public function test_parser_preserves_null_zero_and_negative_values(): void
    {
        $parsed = app(WialonEngineHoursReportParser::class)->parse([
            'from' => CarbonImmutable::parse('2026-07-19 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-19 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => ['header' => ['Unit', 'Engine hours'], 'rows' => 3],
                'rows' => [
                    ['uid' => '1', 'c' => ['Zero', '0']],
                    ['uid' => '2', 'c' => ['Null', '']],
                    ['uid' => '3', 'c' => ['Negative', '-1']],
                ],
            ]],
        ]);

        $rows = collect($parsed['records'])->keyBy('unit_name');
        $this->assertSame(0.0, $rows['Zero']['engine_hours']);
        $this->assertNull($rows['Null']['engine_hours']);
        $this->assertSame('engine_hours_null', $rows['Null']['parse_status']);
        $this->assertNull($rows['Negative']['engine_hours']);
        $this->assertSame('negative_engine_hours', $rows['Negative']['parse_status']);
    }

    public function test_sync_is_idempotent_and_does_not_duplicate_cross_group_rows(): void
    {
        config(['fleet_efficiency.top_working_vehicle_types' => ['loader']]);
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $groupA = ProjectWialonGroup::query()->create(['project_id' => $project->id, 'wialon_group_id' => '601701901', 'name' => 'A', 'ownership_type' => Equipment::OWNERSHIP_NWC]);
        $groupB = ProjectWialonGroup::query()->create(['project_id' => $project->id, 'wialon_group_id' => '601701902', 'name' => 'B', 'ownership_type' => Equipment::OWNERSHIP_NWC]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        Equipment::query()->create([
            'name' => '10-AF-098',
            'wialon_unit_id' => '7001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701901',
            'active' => true,
        ]);

        WialonReportSyncItem::query()->create(['sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20, 'report_date' => '2026-07-19', 'wialon_group_id' => $groupA->wialon_group_id, 'wialon_group_name' => $groupA->name, 'status' => WialonReportSyncItem::STATUS_PENDING]);
        WialonReportSyncItem::query()->create(['sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20, 'report_date' => '2026-07-19', 'wialon_group_id' => $groupB->wialon_group_id, 'wialon_group_name' => $groupB->name, 'status' => WialonReportSyncItem::STATUS_PENDING]);

        $this->app->instance(WialonSessionManager::class, new class extends WialonSessionManager
        {
            public function __construct() {}

            public function sid(): string
            {
                return 'sid';
            }

            public function close(): void {}
        });
        $this->app->instance(WialonEngineHoursReportService::class, new class extends WialonEngineHoursReportService
        {
            public function __construct() {}

            public function executeForGroupWithSession(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
            {
                return [
                    'resource_id' => 1,
                    'template_id' => 9,
                    'template_name' => 'Engine hours: NWC vs İCARƏ (Api)',
                    'from' => $from,
                    'to' => $to,
                    'tables' => [[
                        'index' => 0,
                        'table' => ['header' => ['Unit', 'Engine hours'], 'rows' => 1],
                        'rows' => [['uid' => '7001', 'c' => ['10-AF-098', '8:00:00']]],
                    ]],
                ];
            }
        });

        app(EngineHoursTop20SyncService::class)->run(['limit' => 2]);
        app(EngineHoursTop20SyncService::class)->run(['limit' => 2]);

        $this->assertSame(1, EngineHoursReportUnitDay::query()->count());
        $row = EngineHoursReportUnitDay::query()->firstOrFail();
        $this->assertSame('8.00', (string) $row->engine_hours);
        $this->assertEqualsCanonicalizing(['601701901', '601701902'], $row->source_group_ids_json);
    }

    public function test_run_limits_due_items_to_the_requested_project_and_ownership(): void
    {
        config(['fleet_efficiency.top_working_vehicle_types' => ['loader']]);
        $targetProject = Project::query()->create(['name' => 'Target', 'active' => true]);
        $foreignProject = Project::query()->create(['name' => 'Foreign', 'active' => true]);
        $targetGroup = ProjectWialonGroup::query()->create([
            'project_id' => $targetProject->id,
            'wialon_group_id' => '601701901',
            'name' => 'Target NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $foreignGroup = ProjectWialonGroup::query()->create([
            'project_id' => $foreignProject->id,
            'wialon_group_id' => '601701902',
            'name' => 'Foreign NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);
        Equipment::query()->create([
            'name' => 'Target unit',
            'wialon_unit_id' => '7001',
            'equipment_type_id' => $type->id,
            'project_id' => $targetProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => $targetGroup->wialon_group_id,
            'active' => true,
        ]);

        $targetItem = WialonReportSyncItem::query()->create([
            'sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
            'report_date' => '2026-07-19',
            'wialon_group_id' => $targetGroup->wialon_group_id,
            'wialon_group_name' => $targetGroup->name,
            'status' => WialonReportSyncItem::STATUS_PENDING,
        ]);
        $foreignItem = WialonReportSyncItem::query()->create([
            'sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
            'report_date' => '2026-07-19',
            'wialon_group_id' => $foreignGroup->wialon_group_id,
            'wialon_group_name' => $foreignGroup->name,
            'status' => WialonReportSyncItem::STATUS_PENDING,
        ]);

        $this->app->instance(WialonSessionManager::class, new class extends WialonSessionManager
        {
            public function __construct() {}

            public function sid(): string
            {
                return 'sid';
            }

            public function close(): void {}
        });
        $this->app->instance(WialonEngineHoursReportService::class, new class extends WialonEngineHoursReportService
        {
            public function __construct() {}

            public function executeForGroupWithSession(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
            {
                return [
                    'resource_id' => 1,
                    'template_id' => 9,
                    'template_name' => 'Engine hours',
                    'from' => $from,
                    'to' => $to,
                    'tables' => [[
                        'index' => 0,
                        'table' => ['header' => ['Unit', 'Engine hours'], 'rows' => 1],
                        'rows' => [['uid' => '7001', 'c' => ['Target unit', '1:00:00']]],
                    ]],
                ];
            }
        });

        $result = app(EngineHoursTop20SyncService::class)->run([
            'date' => '2026-07-19',
            'project' => $targetProject->id,
            'ownership' => Equipment::OWNERSHIP_NWC,
            'limit' => 50,
        ]);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(WialonReportSyncItem::STATUS_COMPLETED, $targetItem->refresh()->status);
        $this->assertSame(WialonReportSyncItem::STATUS_PENDING, $foreignItem->refresh()->status);
    }
}
