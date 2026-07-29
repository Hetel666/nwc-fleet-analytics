<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\GeofenceViolationReportImporter;
use App\Services\GeofenceViolationReportParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceViolationReportParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_report_rows_are_filtered_and_only_continuous_periods_are_imported(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 12:00:00');
        $project = Project::create(['name' => 'Füzuli Ağdam yol', 'active' => true]);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701886',
            'name' => 'Füzuli Ağdam yol - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);

        foreach ([
            ['name' => '10-AF-100', 'id' => '601700100'],
            ['name' => '10-AF-101', 'id' => '601700101'],
        ] as $unit) {
            Equipment::create([
                'name' => $unit['name'],
                'wialon_unit_id' => $unit['id'],
                'equipment_type_id' => $excavator->id,
                'project_id' => $project->id,
                'project_wialon_group_id' => $group->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'active' => true,
            ]);
        }

        $from = CarbonImmutable::parse('2026-07-28 00:00:00', 'Asia/Baku');
        $to = CarbonImmutable::parse('2026-07-28 23:59:59', 'Asia/Baku');
        $entry = CarbonImmutable::parse('2026-07-28 10:00:00', 'Asia/Baku')->timestamp;
        $validExit = CarbonImmutable::parse('2026-07-28 14:00:00', 'Asia/Baku')->timestamp;
        $aggregateExit = CarbonImmutable::parse('2026-07-28 16:00:00', 'Asia/Baku')->timestamp;
        $report = $this->report([
            $this->unitRow('10-AF-100', 'Excavator', '601700100', $entry, $validExit, '4:00:00'),
            $this->unitRow('10-AF-101', 'Excavator', '601700101', $entry, $aggregateExit, '4:00:00'),
            $this->unitRow('10-YX-195', 'Pickup', '601677737', $entry, $validExit, '4:00:00'),
        ]);

        $parsed = app(GeofenceViolationReportParser::class)->parse($report, $group, $from, $to);

        $this->assertSame(3, $parsed['source_rows']);
        $this->assertSame(1, $parsed['skipped_types']);
        $this->assertSame(0, $parsed['malformed_rows']);
        $this->assertCount(2, $parsed['records']);
        $this->assertSame(
            ['imported' => 1, 'rejected' => 1],
            app(GeofenceViolationReportImporter::class)->import($parsed['records'])
        );
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'equipment_name' => '10-AF-100',
            'project_id' => $project->id,
            'outside_duration_seconds' => 14_400,
        ]);
        $this->assertDatabaseMissing('geofence_violation_report_rows', [
            'equipment_name' => '10-AF-101',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function report(array $rows): array
    {
        return [
            'tables' => [[
                'table' => [
                    'header' => ['Grouping', 'Произвольный столбец', 'geofence', 'Время входа', 'Время выхода', 'Длительность нахождения'],
                    'header_type' => ['', 'user_column', 'zone_name', 'time_begin', 'time_end', 'duration_in'],
                ],
                'rows' => [[
                    'c' => ['Out of geofences', '', '', '', '', ''],
                    'r' => $rows,
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitRow(
        string $name,
        string $type,
        string $wialonId,
        int $entry,
        int $exit,
        string $duration
    ): array {
        return [
            'uid' => (int) $wialonId,
            't1' => $entry,
            't2' => $exit,
            'c' => [
                $name,
                $type,
                '',
                ['t' => 'entry', 'v' => $entry],
                ['t' => 'exit', 'v' => $exit, 'y' => 40.4093, 'x' => 49.8671],
                $duration,
            ],
        ];
    }
}
