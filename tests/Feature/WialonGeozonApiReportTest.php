<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\Setting;
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

    public function test_foreign_visit_fully_covered_by_home_geofence_is_not_saved(): void
    {
        [$homeProject, $group, $equipment] = $this->fixture();
        $foreignProject = Project::create(['name' => 'Foreign Project', 'active' => true]);
        $this->geofences($homeProject, $foreignProject);

        $result = app(GeofenceReportViolationCalculator::class)->processGroupReport(
            $group,
            [
                $this->record('Foreign Zone', '19', 36000, $equipment->wialon_unit_id),
                $this->record('Home Zone', '20', 36000, $equipment->wialon_unit_id),
            ],
            $this->context(),
            null,
            true
        );

        $this->assertSame(1, $result['home_visits']);
        $this->assertSame(1, $result['foreign_visits']);
        $this->assertSame([], $result['violations']);
        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
    }

    public function test_home_overlap_is_removed_and_midnight_boundary_does_not_leak_into_next_day(): void
    {
        [$homeProject, $group, $equipment] = $this->fixture();
        $foreignProject = Project::create(['name' => 'Foreign Project', 'active' => true]);
        $this->geofences($homeProject, $foreignProject);

        $result = app(GeofenceReportViolationCalculator::class)->processGroupReport(
            $group,
            [
                $this->record('Foreign Zone', '19', 86400, $equipment->wialon_unit_id, '2026-07-16 01:00:00'),
                $this->record('Home Zone', '20', 86400, $equipment->wialon_unit_id, '2026-07-17 00:00:00'),
            ],
            $this->context(),
            null,
            true
        );

        $this->assertCount(1, $result['violations']);
        $this->assertSame('2026-07-16 01:00:00', $result['violations'][0]['entered_at']->toDateTimeString());
        $this->assertSame('2026-07-17 00:00:00', $result['violations'][0]['left_at']->toDateTimeString());
        $this->assertSame(23 * 3600, $result['violations'][0]['duration_seconds']);
        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 1);

        $service = app(GeofenceViolationService::class);
        $this->assertSame(1, $service->summary([
            'date_from' => '2026-07-16',
            'date_to' => '2026-07-16',
            'ownership' => 'all',
        ])['total']);
        $this->assertSame(0, $service->summary([
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
            'ownership' => 'all',
        ])['total']);
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

    public function test_saved_geofence_minimum_hours_setting_controls_dashboard_threshold(): void
    {
        Setting::create([
            'key' => 'geofence_min_exit_minutes',
            'value' => '4',
            'is_secret' => false,
        ]);

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

        $result = app(GeofenceReportViolationCalculator::class)->processGroupReport(
            $group,
            [$this->record('Kəlbəcər yol', '19', 10800, $equipment->wialon_unit_id)],
            $this->context(),
            null,
            true
        );

        $this->assertSame(1, $result['violations_under_threshold']);
        $this->assertSame(0, $result['violations_at_least_threshold']);
        $this->assertSame(0, app(GeofenceViolationService::class)->summary($this->dashboardFilters())['total']);
    }

    public function test_dashboard_uses_wialon_report_home_project_when_unit_current_project_changed(): void
    {
        [$homeProject, $group, $equipment] = $this->fixture('Dump Truck');
        $foreignProject = Project::create(['name' => 'Foreign Project', 'active' => true]);
        $currentProject = Project::create(['name' => 'Current Project', 'active' => true]);

        $this->geofences($homeProject, $foreignProject);

        app(GeofenceReportViolationCalculator::class)->processGroupReport(
            $group,
            [$this->record('Foreign Zone', '19', 10800, $equipment->wialon_unit_id)],
            $this->context(),
            null,
            true
        );

        $equipment->update(['project_id' => $currentProject->id]);

        $filters = [...$this->dashboardFilters(), 'project_id' => $homeProject->id];
        $service = app(GeofenceViolationService::class);

        $this->assertSame(1, $service->summary($filters)['total']);
        $this->assertSame(1, $service->paginate($filters)->total());
        $this->assertCount(1, $service->exportRows($filters));
    }

    public function test_geofence_dashboard_allows_required_vehicle_types_and_excludes_other_types(): void
    {
        [$homeProject, $group, $dumpTruck] = $this->fixture('Dump Truck');
        $foreignProject = Project::create(['name' => 'Foreign Project', 'active' => true]);
        $this->geofences($homeProject, $foreignProject);

        $bulldozer = $this->equipmentFor($homeProject, $group, 'Bulldozer', '7002', '10-BD-001');
        $pickup = $this->equipmentFor($homeProject, $group, 'Pickup', '7003', '10-PU-001');

        $calculator = app(GeofenceReportViolationCalculator::class);
        $calculator->processGroupReport($group, [$this->record('Foreign Zone', '19', 10800, $dumpTruck->wialon_unit_id)], $this->context(), null, true);
        $calculator->processGroupReport($group, [$this->record('Foreign Zone', '19', 10800, $bulldozer->wialon_unit_id, '2026-07-17 14:00:00')], $this->context(), null, true);
        $calculator->processGroupReport($group, [$this->record('Foreign Zone', '19', 10800, $pickup->wialon_unit_id, '2026-07-17 18:00:00')], $this->context(), null, true);

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 3);
        $this->assertSame(2, app(GeofenceViolationService::class)->summary($this->dashboardFilters())['total']);
    }

    public function test_prefabrik_shared_work_geofences_are_not_saved_as_violations(): void
    {
        config([
            'wialon_projects.shared_home_geofences' => [
                'Kəlbəcər yol ümumi' => ['Prefabrik tabor məntəqəsi'],
                'Laçın yol ümumi' => ['Prefabrik tabor məntəqəsi'],
            ],
        ]);

        $prefabrik = Project::create(['name' => 'Prefabrik tabor məntəqəsi', 'active' => true]);
        $kalbajar = Project::create(['name' => 'Kəlbəcər yol', 'active' => true]);
        $lacin = Project::create(['name' => 'Laçın yol', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::create([
            'project_id' => $prefabrik->id,
            'wialon_group_id' => '601701959',
            'name' => 'Prefabrik tabor məntəqəsi - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $equipment = Equipment::create([
            'name' => '77-PF-001',
            'registration_number' => '77-PF-001',
            'wialon_unit_id' => '77001',
            'equipment_type_id' => $type->id,
            'project_id' => $prefabrik->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        Geofence::create([
            'project_id' => $prefabrik->id,
            'name' => 'Prefabrik tabor məntəqəsi',
            'normalized_name' => 'prefabrik tabor məntəqəsi',
            'wialon_geofence_id' => '601701680:38',
            'active' => true,
        ]);
        Geofence::create([
            'project_id' => $kalbajar->id,
            'name' => 'Kəlbəcər yol ümumi',
            'normalized_name' => 'kəlbəcər yol ümumi',
            'wialon_geofence_id' => '601701680:30',
            'active' => true,
        ]);
        Geofence::create([
            'project_id' => $lacin->id,
            'name' => 'Laçın yol ümumi',
            'normalized_name' => 'laçın yol ümumi',
            'wialon_geofence_id' => '601701680:41',
            'active' => true,
        ]);

        $result = app(GeofenceReportViolationCalculator::class)->processGroupReport(
            $group,
            [
                $this->record('Kəlbəcər yol ümumi', '30', 10800, $equipment->wialon_unit_id),
                $this->record('Laçın yol ümumi', '41', 14400, $equipment->wialon_unit_id, '2026-07-17 15:00:00'),
            ],
            $this->context(),
            null,
            true
        );

        $this->assertSame(2, $result['home_visits']);
        $this->assertSame(0, $result['foreign_visits']);
        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
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

    private function fixture(string $typeName = 'Excavator'): array
    {
        config(['fleet.foreign_geofence.min_minutes' => 180]);

        $project = Project::create(['name' => 'Laçın yol', 'active' => true]);
        $type = EquipmentType::create(['name' => $typeName]);
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

    private function equipmentFor(Project $project, ProjectWialonGroup $group, string $typeName, string $wialonUnitId, string $name): Equipment
    {
        $type = EquipmentType::create(['name' => $typeName]);

        return Equipment::create([
            'name' => $name,
            'registration_number' => $name,
            'wialon_unit_id' => $wialonUnitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'active' => true,
        ]);
    }

    private function geofences(Project $homeProject, Project $foreignProject): void
    {
        Geofence::create([
            'project_id' => $homeProject->id,
            'name' => 'Home Zone',
            'normalized_name' => 'home zone',
            'wialon_geofence_id' => '601701680:20',
            'active' => true,
        ]);
        Geofence::create([
            'project_id' => $foreignProject->id,
            'name' => 'Foreign Zone',
            'normalized_name' => 'foreign zone',
            'wialon_geofence_id' => '601701680:19',
            'active' => true,
        ]);
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
