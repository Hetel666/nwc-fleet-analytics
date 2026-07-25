<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use App\Services\GeofenceReportViolationCalculator;
use App\Services\GeofenceViolationService;
use App\Services\WialonGeozonReportParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WialonGeozonApiReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_assigns_parent_geofence_to_child_rows(): void
    {
        $parsed = app(WialonGeozonReportParser::class)->parse([
            'table' => [
                'header' => ['Группировка', 'project', 'geofence', 'Время входа', 'Время выхода', 'Длительность нахождения'],
                'rows' => 1,
            ],
            'rows' => [
                [
                    'c' => ['Laçın yol', '', '', '', '', '99:00:00'],
                    'r' => [
                        ['c' => [['t' => '10-AD-410', 'i' => 7001], 'Laçın yol', '-----', '2026-07-17 10:00:00', '2026-07-17 13:00:00', ['v' => 10800]]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(1, $parsed['parent_rows']);
        $this->assertSame(1, $parsed['nested_rows']);
        $this->assertCount(1, $parsed['records']);
        $this->assertSame('Laçın yol', $parsed['records'][0]['visited_geofence_name']);
        $this->assertSame('10-AD-410', $parsed['records'][0]['unit_name']);
        $this->assertSame('7001', $parsed['records'][0]['wialon_unit_id']);
        $this->assertSame(10800, $parsed['records'][0]['duration_seconds']);
    }

    public function test_home_geofence_visit_is_not_saved_as_violation(): void
    {
        [$project, $group, $equipment] = $this->fixture();
        Geofence::create([
            'project_id' => $project->id,
            'name' => 'Laçın yol',
            'normalized_name' => 'laçın yol',
            'wialon_geofence_id' => '601701680:20',
            'active' => true,
        ]);

        $result = app(GeofenceReportViolationCalculator::class)->processGroupReport(
            $group,
            [$this->record('Laçın yol', '20', 10800, $equipment->wialon_unit_id)],
            $this->context(),
            null,
            true
        );

        $this->assertSame(1, $result['home_visits']);
        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
    }

    public function test_foreign_geofence_visit_is_saved_and_dashboard_uses_threshold(): void
    {
        [$homeProject, $group, $equipment] = $this->fixture();
        $foreignProject = Project::create(['name' => 'Kəlbəcər yol', 'active' => true]);

        Geofence::create([
            'project_id' => $homeProject->id,
            'name' => 'Laçın yol',
            'normalized_name' => 'laçın yol',
            'wialon_geofence_id' => '601701680:20',
            'active' => true,
        ]);
        Geofence::create([
            'project_id' => $foreignProject->id,
            'name' => 'Kəlbəcər yol',
            'normalized_name' => 'kəlbəcər yol',
            'wialon_geofence_id' => '601701680:19',
            'active' => true,
        ]);

        $calculator = app(GeofenceReportViolationCalculator::class);
        $calculator->processGroupReport(
            $group,
            [$this->record('Kəlbəcər yol', '19', 10799, $equipment->wialon_unit_id)],
            $this->context(),
            null,
            true
        );

        $this->assertSame(0, app(GeofenceViolationService::class)->summary($this->dashboardFilters())['total']);

        $calculator->processGroupReport(
            $group,
            [$this->record('Kəlbəcər yol', '19', 10800, $equipment->wialon_unit_id, '2026-07-17 14:00:00')],
            $this->context(),
            null,
            true
        );

        $summary = app(GeofenceViolationService::class)->summary($this->dashboardFilters());

        $this->assertSame(1, $summary['total']);
        $this->assertSame(['Kəlbəcər yol'], $summary['labels']);
    }

    public function test_repeat_sync_does_not_create_duplicate_records(): void
    {
        [$homeProject, $group, $equipment] = $this->fixture();
        $foreignProject = Project::create(['name' => 'Kəlbəcər yol', 'active' => true]);

        Geofence::create([
            'project_id' => $homeProject->id,
            'name' => 'Laçın yol',
            'normalized_name' => 'laçın yol',
            'wialon_geofence_id' => '601701680:20',
            'active' => true,
        ]);
        Geofence::create([
            'project_id' => $foreignProject->id,
            'name' => 'Kəlbəcər yol',
            'normalized_name' => 'kəlbəcər yol',
            'wialon_geofence_id' => '601701680:19',
            'active' => true,
        ]);

        $calculator = app(GeofenceReportViolationCalculator::class);
        $record = $this->record('Kəlbəcər yol', '19', 10800, $equipment->wialon_unit_id);

        $calculator->processGroupReport($group, [$record], $this->context(), null, true);
        $calculator->processGroupReport($group, [$record], $this->context(), null, true);

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 1);
        $this->assertSame(GeofenceReportViolationCalculator::SOURCE, UnitForeignGeofenceInterval::first()->source);
    }

    public function test_carbon_duration_rejects_reversed_interval(): void
    {
        $record = $this->record('Kəlbəcər yol', '19', 10800, '7001');
        $record['entry_at'] = CarbonImmutable::parse('2026-07-17 13:00:00', config('app.timezone'));
        $record['exit_at'] = CarbonImmutable::parse('2026-07-17 10:00:00', config('app.timezone'));
        $record['invalid_reason'] = 'invalid_exit_time';

        [$project, $group] = $this->fixture();
        Geofence::create([
            'project_id' => $project->id,
            'name' => 'Laçın yol',
            'normalized_name' => 'laçın yol',
            'wialon_geofence_id' => '601701680:20',
            'active' => true,
        ]);

        $result = app(GeofenceReportViolationCalculator::class)->processGroupReport($group, [$record], $this->context(), null, true);

        $this->assertSame(1, $result['invalid_rows']);
        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
    }

    private function fixture(): array
    {
        config(['fleet.foreign_geofence.min_minutes' => 180]);

        $project = Project::create(['name' => 'Laçın yol', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701881',
            'name' => 'Laçın yol - İcarə',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'is_active' => true,
        ]);
        $equipment = Equipment::create([
            'name' => '10-AD-410',
            'registration_number' => '10-AD-410',
            'wialon_unit_id' => '7001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'active' => true,
        ]);

        return [$project, $group, $equipment];
    }

    private function record(string $geofenceName, string $geofenceId, int $durationSeconds, string $unitId, string $entry = '2026-07-17 10:00:00'): array
    {
        $entryAt = CarbonImmutable::parse($entry, config('app.timezone'));
        $exitAt = $entryAt->addSeconds($durationSeconds);

        return [
            'wialon_unit_id' => $unitId,
            'unit_name' => '10-AD-410',
            'reported_project' => 'Laçın yol',
            'visited_geofence_id' => $geofenceId,
            'visited_geofence_name' => $geofenceName,
            'visited_geofence_normalized_name' => mb_strtolower($geofenceName),
            'entry_at' => $entryAt,
            'exit_at' => $exitAt,
            'duration_seconds' => $durationSeconds,
            'invalid_reason' => null,
        ];
    }

    private function context(): array
    {
        return [
            'resource_id' => '601701680',
            'template_id' => '30',
            'table_name' => 'geozones',
            'from' => CarbonImmutable::parse('2026-07-17 00:00:00', config('app.timezone')),
            'to' => CarbonImmutable::parse('2026-07-17 23:59:59', config('app.timezone')),
        ];
    }

    private function dashboardFilters(): array
    {
        return [
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
            'ownership' => 'all',
        ];
    }
}
