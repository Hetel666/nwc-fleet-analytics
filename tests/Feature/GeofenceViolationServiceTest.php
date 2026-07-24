<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\UnitForeignGeofenceInterval;
use App\Services\ForeignProjectGeofenceMonitoringService;
use App\Services\GeofenceViolationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceViolationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.foreign_geofence.min_minutes' => 180,
            'fleet.foreign_geofence.stale_after_minutes' => 30,
            'fleet.foreign_geofence.show_all' => false,
            'fleet.foreign_geofence.include_stale' => false,
        ]);
    }

    public function test_summary_modal_and_excel_use_same_current_interval_selection(): void
    {
        [$home, $foreign] = $this->projectsWithGeofences();
        $visible = $this->equipment($home, 'Visible unit');
        $underThreshold = $this->equipment($home, 'Under threshold unit');
        $monitoring = app(ForeignProjectGeofenceMonitoringService::class);

        $monitoring->processUnitPosition($visible, $this->position(25, 25, '2026-07-17 09:00:00'));
        $monitoring->processUnitPosition($underThreshold, $this->position(25, 25, '2026-07-17 11:30:00'));
        $monitoring->processUnitPosition($visible, $this->position(25, 25, '2026-07-17 13:00:00'));
        $monitoring->processUnitPosition($underThreshold, $this->position(25, 25, '2026-07-17 13:00:00'));
        Carbon::setTestNow('2026-07-17 13:00:00');

        $service = app(GeofenceViolationService::class);
        $filters = [
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
            'current_geozone_project_id' => $foreign->id,
        ];

        $this->assertSame(1, $service->summary($filters)['total']);
        $this->assertSame(1, $service->paginate($filters)->total());
        $this->assertCount(1, $service->exportRows($filters));
        $this->assertSame('Visible unit', $service->paginate($filters)->items()[0]['equipment']);
    }

    public function test_stale_and_duplicate_open_intervals_are_not_counted_as_multiple_active_violations(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Duplicated unit');
        $monitoring = app(ForeignProjectGeofenceMonitoringService::class);

        $interval = $monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 09:00:00'));
        $monitoring->processUnitPosition($unit, $this->position(25, 25, '2026-07-17 12:00:00'));

        $duplicate = $interval->refresh()->replicate();
        $duplicate->entered_at = Carbon::parse('2026-07-17 09:01:00');
        $duplicate->last_position_at = Carbon::parse('2026-07-17 12:00:00');
        $duplicate->save();

        Carbon::setTestNow('2026-07-17 12:20:00');
        $service = app(GeofenceViolationService::class);
        $this->assertSame(1, $service->summary($this->filters())['total']);

        Carbon::setTestNow('2026-07-17 12:31:00');
        $this->assertSame(0, $service->summary($this->filters())['total']);
        $this->assertSame(1, $service->baseIntervals($this->filters())->count());
    }

    public function test_closed_historical_interval_overlapping_period_is_not_used_as_current_violation(): void
    {
        [$home, $foreign] = $this->projectsWithGeofences();
        $unit = $this->equipment($home, 'Historical unit');
        $foreignGeofence = Geofence::query()->where('project_id', $foreign->id)->firstOrFail();

        UnitForeignGeofenceInterval::query()->create([
            'unit_id' => $unit->id,
            'wialon_unit_id' => $unit->wialon_unit_id,
            'ownership_type' => $unit->ownership_type,
            'home_project_id' => $home->id,
            'home_project_name' => $home->name,
            'foreign_project_id' => $foreign->id,
            'foreign_project_name' => $foreign->name,
            'foreign_geofence_id' => $foreignGeofence->id,
            'foreign_geofence_name' => $foreignGeofence->name,
            'entered_at' => '2026-07-17 09:00:00',
            'left_at' => '2026-07-17 13:00:00',
            'last_position_at' => '2026-07-17 13:00:00',
            'duration_seconds' => 14400,
            'status' => UnitForeignGeofenceInterval::STATUS_CLOSED,
            'source' => 'wialon_geozone_report',
        ]);

        $service = app(GeofenceViolationService::class);

        $this->assertSame(0, $service->summary($this->filters())['total']);
        $this->assertSame(0, $service->paginate($this->filters())->total());
        $this->assertCount(0, $service->exportRows($this->filters()));
    }

    /**
     * @return array{Project, Project}
     */
    private function projectsWithGeofences(): array
    {
        $home = Project::query()->create(['name' => 'Home Project', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign Project', 'active' => true]);

        $this->geofence($home, 'Home Project', 0, 0, 10, 10);
        $this->geofence($foreign, 'Foreign Project', 20, 20, 30, 30);

        return [$home, $foreign];
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

    private function equipment(Project $project, string $name): Equipment
    {
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Excavator']);

        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701935',
            'active' => true,
        ])->load('type');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'date_from' => '2026-07-17',
            'date_to' => '2026-07-17',
        ];
    }

    /**
     * @return array{lat: float, lng: float, time: string}
     */
    private function position(float $lng, float $lat, string $time): array
    {
        return ['lat' => $lat, 'lng' => $lng, 'time' => $time];
    }
}
