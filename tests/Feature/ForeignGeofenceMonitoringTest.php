<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\UnitForeignGeofenceInterval;
use App\Services\ForeignProjectGeofenceMonitoringService;
use App\Services\GeofenceReportViolationCalculator;
use App\Services\GeofenceViolationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForeignGeofenceMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private ForeignProjectGeofenceMonitoringService $monitoring;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.foreign_geofence.min_minutes' => 180,
            'fleet.foreign_geofence.show_all' => false,
            'fleet.foreign_geofence.stale_after_minutes' => 30,
        ]);

        $this->monitoring = app(ForeignProjectGeofenceMonitoringService::class);
    }

    public function test_outside_home_without_entering_foreign_geofence_is_not_shown(): void
    {
        [$home, $foreign] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        Carbon::setTestNow('2026-07-17 13:00:00');
        $this->monitoring->processUnitPosition($unit, $this->position(15, 15, '2026-07-17 10:00:00'));

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
        $this->assertSame(0, app(GeofenceViolationService::class)->summary($this->filters())['total']);
    }

    public function test_entering_foreign_project_geofence_opens_interval_and_counts_current_geofence_project(): void
    {
        [$home, $foreign] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:05:00'));
        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 13:05:00'));
        Carbon::setTestNow('2026-07-17 13:06:00');

        $summary = app(GeofenceViolationService::class)->summary($this->filters());

        $this->assertSame(1, $summary['total']);
        $this->assertSame([$foreign->name], $summary['labels']);
        $this->assertSame([1], $summary['counts']);
    }

    public function test_duration_starts_from_foreign_geofence_entry_time(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(15, 15, '2026-07-17 09:15:00'));
        $interval = $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:05:00'));
        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 14:35:00'));
        Carbon::setTestNow('2026-07-17 14:35:00');

        $this->assertNotNull($interval);
        $this->assertSame('2026-07-17 10:05:00', $interval->entered_at->format('Y-m-d H:i:s'));
        $this->assertSame(16200, $this->monitoring->effectiveDurationSeconds($interval->refresh()));
    }

    public function test_returning_to_home_geofence_closes_open_interval(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unit, $this->position(5, 5, '2026-07-17 11:00:00'));

        $interval = UnitForeignGeofenceInterval::query()->firstOrFail();
        $this->assertSame(UnitForeignGeofenceInterval::STATUS_CLOSED, $interval->status);
        $this->assertSame('2026-07-17 11:00:00', $interval->left_at->format('Y-m-d H:i:s'));
    }

    public function test_leaving_foreign_geofence_to_no_project_geofence_closes_interval(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unit, $this->position(15, 15, '2026-07-17 12:00:00'));

        $this->assertSame(UnitForeignGeofenceInterval::STATUS_CLOSED, UnitForeignGeofenceInterval::query()->firstOrFail()->status);
    }

    public function test_switching_to_another_foreign_geofence_closes_old_and_opens_new(): void
    {
        [$home, $foreign, $third] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unit, $this->position(15, 15, '2026-07-17 12:00:00'));
        $this->monitoring->processUnitPosition($unit, $this->position(45, 45, '2026-07-17 12:20:00'));

        $this->assertSame(2, UnitForeignGeofenceInterval::query()->count());
        $this->assertSame(UnitForeignGeofenceInterval::STATUS_CLOSED, UnitForeignGeofenceInterval::query()->oldest('id')->first()->status);
        $this->assertSame($third->id, UnitForeignGeofenceInterval::query()->where('status', 'open')->firstOrFail()->foreign_project_id);
        $this->monitoring->processUnitPosition($unit, $this->position(45, 45, '2026-07-17 15:21:00'));
        Carbon::setTestNow('2026-07-17 15:21:00');
        $this->assertSame([$third->name], app(GeofenceViolationService::class)->summary($this->filters())['labels']);
    }

    public function test_interval_below_three_hours_is_hidden_and_three_hours_is_visible(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');
        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 12:59:00'));
        Carbon::setTestNow('2026-07-17 12:59:00');
        $this->assertSame(0, app(GeofenceViolationService::class)->summary($this->filters())['total']);

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 13:00:00'));
        Carbon::setTestNow('2026-07-17 13:00:00');
        $this->assertSame(1, app(GeofenceViolationService::class)->summary($this->filters())['total']);
    }

    public function test_stale_position_uses_last_position_as_duration_upper_bound(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');
        $interval = $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $interval->update(['last_position_at' => '2026-07-17 13:00:00']);
        Carbon::setTestNow('2026-07-17 13:40:00');

        $this->assertTrue($this->monitoring->isStale($interval->refresh()));
        $this->assertSame(10800, $this->monitoring->effectiveDurationSeconds($interval));
    }

    public function test_carbon_three_duration_boundaries_are_positive_and_filter_correctly(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unitAtThreeHours = $this->equipment($home, 'Excavator', 'Exactly 3h unit');
        $unitBelowThreeHours = $this->equipment($home, 'Excavator', 'Below 3h unit');

        $threeHourInterval = $this->monitoring->processUnitPosition($unitAtThreeHours, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unitAtThreeHours, $this->position(25, 25, '2026-07-17 13:00:00'));
        $belowInterval = $this->monitoring->processUnitPosition($unitBelowThreeHours, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unitBelowThreeHours, $this->position(25, 25, '2026-07-17 12:59:59'));
        Carbon::setTestNow('2026-07-17 13:29:00');

        $this->assertSame(10800, $this->monitoring->effectiveDurationSeconds($threeHourInterval->refresh()));
        $this->assertSame(10799, $this->monitoring->effectiveDurationSeconds($belowInterval->refresh()));
        $this->assertGreaterThan(0, $this->monitoring->effectiveDurationSeconds($threeHourInterval));
        $this->assertSame(1, app(GeofenceViolationService::class)->summary($this->filters())['total']);
    }

    public function test_stale_interval_is_excluded_from_active_dashboard_count_by_default(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $interval = $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $interval->update(['last_position_at' => '2026-07-17 13:00:00']);
        Carbon::setTestNow('2026-07-17 13:40:00');

        $service = app(GeofenceViolationService::class);

        $this->assertTrue($this->monitoring->isStale($interval->refresh()));
        $this->assertSame(1, $service->baseIntervals($this->filters())->count());
        $this->assertSame(0, $service->summary($this->filters())['total']);
    }

    public function test_only_allowed_vehicle_types_are_included_and_bakhoe_loader_is_normalized(): void
    {
        [$home] = $this->projectsWithGeofences();
        $allowed = $this->equipment($home, 'Bakhoe Loader', 'Bakhoe Loader unit');
        $notAllowed = $this->equipment($home, 'Minibus', 'Minibus unit');

        $this->monitoring->processUnitPosition($allowed, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($notAllowed, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($allowed, $this->position(25, 25, '2026-07-17 13:01:00'));
        $this->monitoring->processUnitPosition($notAllowed, $this->position(25, 25, '2026-07-17 13:01:00'));
        Carbon::setTestNow('2026-07-17 13:01:00');

        $rows = app(GeofenceViolationService::class)->exportRows($this->filters());

        $this->assertCount(1, $rows);
        $this->assertContains('Backhoe Loader', $rows[0]);
        $this->assertStringNotContainsString('Minibus', implode(' ', $rows[0]));
    }

    public function test_unit_without_home_project_geofence_is_not_monitored(): void
    {
        $homeWithoutGeofence = Project::query()->create(['name' => 'Home without geofence', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign with geofence', 'active' => true]);
        $this->geofence($foreign, 'Foreign zone', 20, 20, 30, 30);
        $unit = $this->equipment($homeWithoutGeofence, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        Carbon::setTestNow('2026-07-17 13:01:00');

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
        $this->assertSame(0, app(GeofenceViolationService::class)->summary($this->filters())['total']);
    }

    public function test_shared_allowed_home_geofence_does_not_create_false_violation(): void
    {
        config([
            'wialon_projects.shared_home_geofences' => [
                'Shared Plant' => ['Home A', 'Home B'],
            ],
        ]);

        $home = Project::query()->create(['name' => 'Home A', 'active' => true]);
        $otherHome = Project::query()->create(['name' => 'Home B', 'active' => true]);
        $this->geofence($otherHome, 'Shared Plant', 20, 20, 30, 30);
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        Carbon::setTestNow('2026-07-17 13:30:00');

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
        $this->assertSame('inside_home_geofence', $this->monitoring->analyzeUnitPosition($unit, $this->position(25, 25, '2026-07-17 13:30:00'))['reason']);
    }

    public function test_any_allowed_home_geofence_counts_as_home(): void
    {
        $home = Project::query()->create(['name' => 'Home multi-zone', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign', 'active' => true]);
        $this->geofence($home, 'Home zone A', 0, 0, 10, 10);
        $this->geofence($home, 'Home zone B', 20, 20, 30, 30);
        $this->geofence($foreign, 'Foreign zone', 40, 40, 50, 50);
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
    }

    public function test_home_geofence_has_priority_when_geofences_overlap(): void
    {
        $home = Project::query()->create(['name' => 'Home overlap', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign overlap', 'active' => true]);
        $this->geofence($home, 'Home wide zone', 0, 0, 10, 10);
        $this->geofence($foreign, 'Foreign small zone', 2, 2, 3, 3);
        $unit = $this->equipment($home, 'Excavator');

        $this->monitoring->processUnitPosition($unit, $this->position(2.5, 2.5, '2026-07-17 10:00:00'));

        $this->assertDatabaseCount('unit_foreign_geofence_intervals', 0);
        $this->assertSame('inside_home_geofence', $this->monitoring->analyzeUnitPosition($unit, $this->position(2.5, 2.5, '2026-07-17 10:00:00'))['reason']);
    }

    public function test_smallest_foreign_geofence_is_selected_when_foreign_geofences_overlap(): void
    {
        $home = Project::query()->create(['name' => 'Home outside overlap', 'active' => true]);
        $foreignLarge = Project::query()->create(['name' => 'Foreign large', 'active' => true]);
        $foreignSmall = Project::query()->create(['name' => 'Foreign small', 'active' => true]);
        $this->geofence($home, 'Home zone', 0, 0, 1, 1);
        $this->geofence($foreignLarge, 'Foreign large zone', 10, 10, 30, 30);
        $smallGeofence = $this->geofence($foreignSmall, 'Foreign small zone', 20, 20, 21, 21);
        $unit = $this->equipment($home, 'Excavator');

        $interval = $this->monitoring->processUnitPosition($unit, $this->position(20.5, 20.5, '2026-07-17 10:00:00'));

        $this->assertNotNull($interval);
        $this->assertSame($smallGeofence->id, $interval->foreign_geofence_id);
        $this->assertSame($foreignSmall->id, $interval->foreign_project_id);
    }

    public function test_invalid_position_time_does_not_close_open_interval_with_current_time(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');

        $interval = $this->monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 10:00:00'));
        Carbon::setTestNow('2026-07-17 14:00:00');
        $this->monitoring->processUnitPosition($unit, ['lat' => 5, 'lng' => 5]);

        $interval->refresh();
        $this->assertSame(UnitForeignGeofenceInterval::STATUS_OPEN, $interval->status);
        $this->assertNull($interval->left_at);
        $this->assertSame('2026-07-17 10:00:00', $interval->last_position_at->format('Y-m-d H:i:s'));
        $this->assertSame('invalid_position_time', $this->monitoring->analyzeUnitPosition($unit, ['lat' => 5, 'lng' => 5])['reason']);
    }

    public function test_reprocessing_same_foreign_position_does_not_duplicate_open_interval(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Excavator');
        $position = $this->position(25, 25, '2026-07-17 10:00:00');

        $this->monitoring->processUnitPosition($unit, $position);
        $this->monitoring->processUnitPosition($unit, $position);

        $this->assertSame(1, UnitForeignGeofenceInterval::query()->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)->count());
    }

    public function test_project_filter_uses_home_project_and_current_geozone_filter_uses_foreign_project(): void
    {
        [$home, $foreign, $third] = $this->projectsWithGeofences();
        $homeUnit = $this->equipment($home, 'Excavator', 'Home A unit');
        $thirdHomeUnit = $this->equipment($third, 'Excavator', 'Home C unit');

        $this->monitoring->processUnitPosition($homeUnit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($thirdHomeUnit, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($homeUnit, $this->position(25, 25, '2026-07-17 13:01:00'));
        $this->monitoring->processUnitPosition($thirdHomeUnit, $this->position(25, 25, '2026-07-17 13:01:00'));
        Carbon::setTestNow('2026-07-17 13:01:00');

        $service = app(GeofenceViolationService::class);

        $this->assertSame(1, $service->summary([...$this->filters(), 'project_id' => $home->id])['total']);
        $this->assertSame(1, $service->summary([...$this->filters(), 'project_id' => $third->id])['total']);
        $this->assertSame(2, $service->summary([...$this->filters(), 'current_geozone_project_id' => $foreign->id])['total']);
    }

    public function test_dashboard_modal_and_export_counts_use_the_same_selection(): void
    {
        [$home, $foreign] = $this->projectsWithGeofences();
        $visible = $this->equipment($home, 'Excavator', 'Visible unit');
        $belowMinimum = $this->equipment($home, 'Excavator', 'Below minimum unit');

        $this->monitoring->processUnitPosition($visible, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($belowMinimum, $this->position(25, 25, '2026-07-17 12:30:00'));
        $this->monitoring->processUnitPosition($visible, $this->position(25, 25, '2026-07-17 13:01:00'));
        $this->monitoring->processUnitPosition($belowMinimum, $this->position(25, 25, '2026-07-17 13:01:00'));
        Carbon::setTestNow('2026-07-17 13:01:00');

        $service = app(GeofenceViolationService::class);
        $filters = $this->filters();
        $summary = $service->summary($filters);
        $modal = $service->paginate([...$filters, 'current_geozone_project_id' => $foreign->id]);
        $exportRows = $service->exportRows([...$filters, 'current_geozone_project_id' => $foreign->id]);

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, array_sum($summary['counts']));
        $this->assertSame(1, $modal->total());
        $this->assertCount(1, $exportRows);
        $this->assertContains('Visible unit', $exportRows[0]);
    }

    public function test_modal_filter_returns_only_selected_current_geozone(): void
    {
        [$home, $foreign, $third] = $this->projectsWithGeofences();
        $unitA = $this->equipment($home, 'Excavator', 'Unit in B');
        $unitB = $this->equipment($home, 'Excavator', 'Unit in C');

        $this->monitoring->processUnitPosition($unitA, $this->position(25, 25, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unitB, $this->position(45, 45, '2026-07-17 10:00:00'));
        $this->monitoring->processUnitPosition($unitA, $this->position(25, 25, '2026-07-17 13:01:00'));
        $this->monitoring->processUnitPosition($unitB, $this->position(45, 45, '2026-07-17 13:01:00'));
        Carbon::setTestNow('2026-07-17 13:01:00');

        $rows = app(GeofenceViolationService::class)->exportRows([
            ...$this->filters(),
            'current_geozone_project_id' => $foreign->id,
            'current_geozone_id' => Geofence::query()->where('project_id', $foreign->id)->value('id'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertContains('Unit in B', $rows[0]);
        $this->assertNotContains('Unit in C', $rows[0]);
        $this->assertSame($third->id, UnitForeignGeofenceInterval::query()->where('foreign_project_id', $third->id)->firstOrFail()->foreign_project_id);
    }

    public function test_dashboard_summary_modal_and_excel_filter_by_home_project_before_grouping(): void
    {
        [$lacin, $kalbajar, $qazax] = $this->projectsWithGeofences();
        $fuzuli = Project::query()->create(['name' => 'Füzuli Xocavənd yol', 'active' => true]);
        $this->geofence($fuzuli, 'Füzuli Xocavənd yol', 60, 60, 70, 70);

        $lacinUnitInKalbajar = $this->equipment($lacin, 'Excavator', 'Laçın unit Kalbajar');
        $lacinUnitInQazax = $this->equipment($lacin, 'Road Roller', 'Laçın unit Qazax');
        $fuzuliUnitInKalbajar = $this->equipment($fuzuli, 'Loader', 'Füzuli unit Kalbajar');

        $kalbajarGeofence = Geofence::query()->where('project_id', $kalbajar->id)->firstOrFail();
        $qazaxGeofence = Geofence::query()->where('project_id', $qazax->id)->firstOrFail();

        $this->reportInterval($lacinUnitInKalbajar, $lacin, $kalbajar, $kalbajarGeofence);
        $this->reportInterval($lacinUnitInQazax, $lacin, $qazax, $qazaxGeofence);
        $this->reportInterval($fuzuliUnitInKalbajar, $fuzuli, $kalbajar, $kalbajarGeofence);

        $service = app(GeofenceViolationService::class);
        $filters = $this->filters();
        $lacinFilters = [...$filters, 'project_id' => $lacin->id];
        $lacinKalbajarFilters = [
            ...$lacinFilters,
            'current_geozone_project_id' => $kalbajar->id,
            'current_geozone_id' => $kalbajarGeofence->id,
        ];

        $allSummary = $service->summary($filters);
        $lacinSummary = $service->summary($lacinFilters);
        $kalbajarRow = collect($lacinSummary['rows'])->firstWhere('project_id', $kalbajar->id);
        $qazaxRow = collect($lacinSummary['rows'])->firstWhere('project_id', $qazax->id);
        $modal = $service->paginate($lacinKalbajarFilters);
        $excelRows = $service->exportRows($lacinKalbajarFilters);

        $this->assertSame(3, $allSummary['total']);
        $this->assertSame(2, collect($allSummary['rows'])->firstWhere('project_id', $kalbajar->id)['count']);
        $this->assertSame(2, $lacinSummary['total']);
        $this->assertSame(1, $kalbajarRow['count']);
        $this->assertSame(1, $qazaxRow['count']);
        $this->assertSame(1, $modal->total());
        $this->assertCount(1, $excelRows);
        $this->assertContains('Laçın unit Kalbajar', $excelRows[0]);
        $this->assertNotContains('Füzuli unit Kalbajar', $excelRows[0]);
        $this->assertSame(0, $service->summary([...$filters, 'project_id' => 999999])['total']);
    }

    public function test_summary_does_not_query_home_geofences_per_interval(): void
    {
        $foreign = Project::query()->create(['name' => 'Foreign shared destination', 'active' => true]);
        $foreignGeofence = $this->geofence($foreign, 'Foreign shared destination', 60, 60, 70, 70);

        for ($index = 1; $index <= 12; $index++) {
            $home = Project::query()->create(['name' => 'Home '.$index, 'active' => true]);
            $this->geofence($home, 'Home '.$index, 0, 0, 10, 10);
            $unit = $this->equipment($home, 'Excavator', 'Unit '.$index);

            $this->reportInterval($unit, $home, $foreign, $foreignGeofence);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = mb_strtolower((string) $query->sql);

            if (str_contains($sql, 'from "geofences"') || str_contains($sql, 'from `geofences`')) {
                $queries[] = $query->sql;
            }
        });

        $summary = app(GeofenceViolationService::class)->summary($this->filters());

        $this->assertSame(12, $summary['total']);
        $this->assertLessThanOrEqual(4, count($queries), 'Geofence queries should stay bounded and not run once per interval.');
    }

    /**
     * @return array{Project, Project, Project}
     */
    private function projectsWithGeofences(): array
    {
        $home = Project::query()->create(['name' => 'Ağdam Azərsu', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Yuxarı Şirvan LOT3', 'active' => true]);
        $third = Project::query()->create(['name' => 'Laçın yol', 'active' => true]);

        $this->geofence($home, 'Ağdam Azərsu', 0, 0, 10, 10);
        $this->geofence($foreign, 'Yuxarı Şirvan LOT3', 20, 20, 30, 30);
        $this->geofence($third, 'Laçın yol', 40, 40, 50, 50);

        return [$home, $foreign, $third];
    }

    private function geofence(Project $project, string $name, float $minLng, float $minLat, float $maxLng, float $maxLat): Geofence
    {
        return Geofence::query()->create([
            'name' => $name,
            'project_id' => $project->id,
            'wialon_geofence_id' => uniqid('zone-', true),
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$minLng, $minLat],
                    [$maxLng, $minLat],
                    [$maxLng, $maxLat],
                    [$minLng, $maxLat],
                    [$minLng, $minLat],
                ]],
            ],
            'active' => true,
        ]);
    }

    private function equipment(Project $project, string $typeName, string $name = 'Unit 01'): Equipment
    {
        $type = EquipmentType::query()->firstOrCreate(['name' => $typeName]);

        return Equipment::query()->create([
            'name' => $name,
            'registration_number' => '90-AA-001',
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701957',
            'active' => true,
        ])->load('type');
    }

    private function reportInterval(Equipment $unit, Project $homeProject, Project $foreignProject, Geofence $foreignGeofence): UnitForeignGeofenceInterval
    {
        return UnitForeignGeofenceInterval::query()->create([
            'unit_id' => $unit->id,
            'wialon_unit_id' => $unit->wialon_unit_id,
            'source_group_id' => '601701957',
            'source_group_name' => $homeProject->name.' - '.$unit->ownership_type,
            'source_group_ids_json' => ['601701957'],
            'ownership_type' => $unit->ownership_type,
            'home_project_id' => $homeProject->id,
            'home_project_name' => $homeProject->name,
            'home_geofence_id' => Geofence::query()->where('project_id', $homeProject->id)->value('id'),
            'home_geofence_names_json' => [$homeProject->name],
            'foreign_project_id' => $foreignProject->id,
            'foreign_project_name' => $foreignProject->name,
            'foreign_geofence_id' => $foreignGeofence->id,
            'foreign_geofence_name' => $foreignGeofence->name,
            'entered_at' => Carbon::parse('2026-07-17 08:00:00'),
            'left_at' => Carbon::parse('2026-07-17 12:00:00'),
            'last_position_at' => Carbon::parse('2026-07-17 12:00:00'),
            'duration_seconds' => 14400,
            'status' => UnitForeignGeofenceInterval::STATUS_CLOSED,
            'source' => GeofenceReportViolationCalculator::SOURCE,
            'match_status' => 'matched',
            'calculated_at' => Carbon::parse('2026-07-17 12:00:00'),
        ]);
    }

    /**
     * @return array{lat: float, lng: float, time: string}
     */
    private function position(float $lng, float $lat, string $time): array
    {
        return ['lat' => $lat, 'lng' => $lng, 'time' => $time];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
            'ownership' => 'all',
        ];
    }
}
