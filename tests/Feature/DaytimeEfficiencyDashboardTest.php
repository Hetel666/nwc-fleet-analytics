<?php

namespace Tests\Feature;

use App\Models\DaytimeEfficiencyFact;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use App\Services\DaytimeEfficiencyClassifier;
use App\Services\DaytimeEfficiencyDashboardService;
use App\Services\WialonDaytimeEfficiencyReportParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DaytimeEfficiencyDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifier_uses_exact_daytime_boundaries(): void
    {
        $classifier = app(DaytimeEfficiencyClassifier::class);

        $this->assertSame('no_data', $classifier->classify(null, false, false, true)['detail_status']);
        $this->assertSame('not_working', $classifier->classify(0.0, true, true, false)['detail_status']);
        $this->assertSame('between_0_and_1', $classifier->classify(0.9999, true, true, false)['category']);
        $this->assertSame('between_1_and_7', $classifier->classify(1.0, true, true, false)['category']);
        $this->assertSame('between_1_and_7', $classifier->classify(6.9999, true, true, false)['category']);
        $this->assertSame('between_7_and_10', $classifier->classify(7.0, true, true, false)['category']);
        $this->assertSame('between_7_and_10', $classifier->classify(9.99, true, true, false)['category']);
        $this->assertSame('between_7_and_10', $classifier->classify(10.0, true, true, false)['category']);
        $this->assertSame('over_10', $classifier->classify(10.0001, true, true, false)['category']);
    }

    public function test_parser_preserves_wialon_daytime_values_and_baku_timestamps(): void
    {
        $date = CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku');
        $parsed = app(WialonDaytimeEfficiencyReportParser::class)->parse([
            'from' => $date,
            'tables' => [[
                'table' => [
                    'name' => 'unit_group_engine_hours',
                    'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End'],
                    'header_type' => ['', 'user_column', 'user_column', 'duration', 'user_column', 'user_column', 'user_column', 'duration_stay', 'correct_mileage', 'time_begin', 'time_end'],
                ],
                'rows' => [[
                    'uid' => '600261257',
                    't1' => CarbonImmutable::parse('2026-07-29 08:18:06', 'Asia/Baku')->timestamp,
                    't2' => CarbonImmutable::parse('2026-07-29 17:36:02', 'Asia/Baku')->timestamp,
                    'c' => ['10-AD-725', 'B160', 'LIUGONG', '2.11', 'Бульдозер', 'NWC', '2023', '0.71', '4.04 км', '2026-07-29 08:18:06', '2026-07-29 17:36:02'],
                ]],
            ]],
        ]);

        $record = $parsed['records'][0];
        $this->assertSame('10-AD-725', $record['unit_name']);
        $this->assertSame('2.11', $record['raw_engine_hours']);
        $this->assertSame(2.11, $record['engine_hours_decimal']);
        $this->assertSame('NWC', $record['vendor']);
        $this->assertSame(4.04, $record['mileage_adjusted']);
        $this->assertSame('2026-07-29 08:18:06', $record['beginning_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-29 17:36:02', $record['end_at']->format('Y-m-d H:i:s'));
    }

    public function test_parser_does_not_shift_values_when_optional_wialon_columns_are_removed(): void
    {
        $date = CarbonImmutable::parse('2026-07-29 00:00:00', 'Asia/Baku');
        $parsed = app(WialonDaytimeEfficiencyReportParser::class)->parse([
            'from' => $date,
            'tables' => [[
                'table' => [
                    'name' => 'unit_group_engine_hours',
                    'header' => ['Grouping', 'Engine hours', 'Equipment Type', 'Vendor', 'Beginning', 'End'],
                    'header_type' => ['', 'duration', 'user_column', 'user_column', 'time_begin', 'time_end'],
                ],
                'rows' => [[
                    'uid' => '600261257',
                    'c' => ['10-AD-725', '2.11', 'Bulldozer', 'NWC', '2026-07-29 08:18:06', '2026-07-29 17:36:02'],
                ]],
            ]],
        ]);

        $record = $parsed['records'][0];
        $this->assertSame('10-AD-725', $record['unit_name']);
        $this->assertSame(2.11, $record['engine_hours_decimal']);
        $this->assertSame('Bulldozer', $record['wialon_equipment_type']);
        $this->assertSame('NWC', $record['vendor']);
        $this->assertNull($record['idling_hours']);
        $this->assertNull($record['mileage_adjusted']);
        $this->assertSame('2026-07-29 08:18:06', $record['beginning_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-29 17:36:02', $record['end_at']->format('Y-m-d H:i:s'));
    }

    public function test_dashboard_renders_separate_nwc_and_icare_daytime_blocks(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $project = Project::query()->firstOrFail();
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Bulldozer']);
        $equipment = Equipment::query()->create([
            'name' => '10-AD-725',
            'wialon_unit_id' => '600261257',
            'project_id' => $project->id,
            'equipment_type_id' => $type->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        DaytimeEfficiencyFact::query()->create([
            'fact_date' => '2026-07-29',
            'equipment_id' => $equipment->id,
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'unit_name_snapshot' => $equipment->name,
            'project_id' => $project->id,
            'project_name_snapshot' => $project->name,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'equipment_type_id' => $type->id,
            'equipment_type_canonical' => 'Bulldozer',
            'wialon_equipment_type' => 'Бульдозер',
            'report_template_name' => 'Qrup report daytime (api)',
            'report_row_found' => true,
            'raw_engine_hours' => '2.11',
            'engine_hours_decimal' => 2.11,
            'raw_mileage' => '4.04 км',
            'mileage_adjusted' => 4.04,
            'category' => 'between_1_and_7',
            'detail_status' => 'normal',
            'parse_status' => 'parsed',
            'calculated_at' => now('Asia/Baku'),
        ]);

        $this->assertDatabaseHas('daytime_efficiency_facts', [
            'fact_date' => '2026-07-29',
            'equipment_id' => $equipment->id,
            'category' => 'between_1_and_7',
        ]);
        $this->assertSame(1, app(DaytimeEfficiencyDashboardService::class)->summary([
            'date_from' => '2026-07-29',
            'date_to' => '2026-07-29',
        ], Equipment::OWNERSHIP_NWC)['between_1_and_7']);

        $response = $this->actingAs($admin)->get('/daytime-efficiency?date_from=2026-07-29&date_to=2026-07-29');

        $response->assertOk()
            ->assertSee('Effektivlik gündüz: NWC üzrə')
            ->assertSee('Effektivlik gündüz: İCARƏ üzrə')
            ->assertSee('Qrup report daytime (api)')
            ->assertSee('10-AD-725')
            ->assertSee('2.11')
            ->assertDontSee('Qrup report overtime (api)');

        $this->actingAs($admin)
            ->get('/dashboard?tab=efficiency&date_from=2026-07-29&date_to=2026-07-29')
            ->assertOk()
            ->assertSee('data-dashboard-widget="daytime-efficiency"', false)
            ->assertSee('Effektivlik gündüz: NWC üzrə')
            ->assertSee('Qrup report daytime (api)')
            ->assertSee('>1</strong>', false);
    }

    public function test_category_drill_down_keeps_date_and_ownership_filters(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $project = Project::query()->firstOrFail();
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Bulldozer']);
        $equipment = Equipment::query()->create([
            'name' => '10-AF-171',
            'wialon_unit_id' => '600261258',
            'project_id' => $project->id,
            'equipment_type_id' => $type->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        DaytimeEfficiencyFact::query()->create([
            'fact_date' => '2026-07-29',
            'equipment_id' => $equipment->id,
            'unit_name_snapshot' => $equipment->name,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'equipment_type_id' => $type->id,
            'equipment_type_canonical' => 'Road Roller',
            'report_row_found' => true,
            'raw_engine_hours' => '0.51',
            'engine_hours_decimal' => 0.51,
            'category' => 'between_0_and_1',
            'detail_status' => 'normal',
            'parse_status' => 'parsed',
            'calculated_at' => now('Asia/Baku'),
        ]);

        $this->actingAs($admin)
            ->get(route('daytime-efficiency.index', [
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-29',
                'ownership_type' => 'nwc',
                'category' => 'between_0_and_1',
            ]))
            ->assertOk()
            ->assertSee('10-AF-171')
            ->assertSee('0.51');
    }
}
