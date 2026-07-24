<?php

namespace Tests\Feature;

use App\Contracts\UnitPositionSource;
use App\Data\UnitPositionData;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\UnitForeignGeofenceInterval;
use App\Services\ForeignProjectGeofenceMonitoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class MonitorForeignGeofencesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.foreign_geofence.monitoring_enabled' => true,
            'fleet.foreign_geofence.monitoring_batch_size' => 2,
            'fleet.foreign_geofence.monitoring_lock_seconds' => 120,
            'fleet.foreign_geofence.monitoring_future_skew_seconds' => 300,
            'fleet.foreign_geofence.stale_after_minutes' => 30,
        ]);

        Carbon::setTestNow('2026-07-17 13:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_processes_fresh_position_and_opens_interval(): void
    {
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home);

        $this->bindPositions([
            $unit->id => $this->positionData($unit, 25, 25, '2026-07-17 12:55:00'),
        ]);

        $this->assertCommandOutputContains('fleet:monitor-foreign-geofences', [
            'Foreign geofence monitoring summary:',
            'candidates: 1',
            'positions: 1',
            'processed: 1',
        ]);

        $this->assertDatabaseHas('unit_foreign_geofence_intervals', [
            'unit_id' => $unit->id,
            'status' => UnitForeignGeofenceInterval::STATUS_OPEN,
        ]);
    }

    public function test_feature_disabled_does_not_call_source_without_force(): void
    {
        config(['fleet.foreign_geofence.monitoring_enabled' => false]);
        $this->app->bind(UnitPositionSource::class, FailingPositionSource::class);

        $this->assertCommandOutputContains('fleet:monitor-foreign-geofences', [
            'Foreign geofence monitoring is disabled.',
        ]);
    }

    public function test_force_allows_manual_run_when_feature_is_disabled(): void
    {
        config(['fleet.foreign_geofence.monitoring_enabled' => false]);
        [$home] = $this->projectsWithGeofences();
        $unit = $this->equipment($home);

        $this->bindPositions([
            $unit->id => $this->positionData($unit, 25, 25, '2026-07-17 12:55:00'),
        ]);

        $this->assertCommandOutputContains('fleet:monitor-foreign-geofences --force', [
            'processed: 1',
        ]);

        $this->assertDatabaseHas('unit_foreign_geofence_intervals', [
            'unit_id' => $unit->id,
            'status' => UnitForeignGeofenceInterval::STATUS_OPEN,
        ]);
    }

    public function test_stale_invalid_future_and_missing_positions_are_skipped_without_mutating_intervals(): void
    {
        [$home] = $this->projectsWithGeofences();
        $stale = $this->equipment($home);
        $invalid = $this->equipment($home);
        $future = $this->equipment($home);
        $missing = $this->equipment($home);

        app(ForeignProjectGeofenceMonitoringService::class)
            ->processUnitPosition($stale, $this->position(25, 25, '2026-07-17 12:40:00'));

        $this->bindPositions([
            $stale->id => $this->positionData($stale, 5, 5, '2026-07-17 11:00:00'),
            $invalid->id => new UnitPositionData($invalid->id, $invalid->wialon_unit_id, 25, 25, null),
            $future->id => $this->positionData($future, 25, 25, '2026-07-17 13:10:01'),
        ]);

        $this->assertCommandOutputContains('fleet:monitor-foreign-geofences', [
            'candidates: 4',
            'missing_position: 1',
            'invalid_position: 1',
            'future_position: 1',
            'stale_position: 1',
            'processed: 0',
        ]);

        $this->assertDatabaseHas('unit_foreign_geofence_intervals', [
            'unit_id' => $stale->id,
            'status' => UnitForeignGeofenceInterval::STATUS_OPEN,
            'left_at' => null,
        ]);
    }

    public function test_existing_lock_prevents_processing(): void
    {
        [$home] = $this->projectsWithGeofences();
        $this->equipment($home);
        $lock = Cache::lock('fleet:monitor-foreign-geofences', 120);
        $this->assertTrue($lock->get());
        $this->app->bind(UnitPositionSource::class, FailingPositionSource::class);

        try {
            $this->assertCommandOutputContains('fleet:monitor-foreign-geofences', [
                'Foreign geofence monitoring is already running.',
            ]);
        } finally {
            $lock->release();
        }
    }

    public function test_partial_unit_failure_is_reported_and_other_units_continue(): void
    {
        [$home] = $this->projectsWithGeofences();
        $first = $this->equipment($home);
        $second = $this->equipment($home);

        $this->bindPositions([
            $first->id => $this->positionData($first, 25, 25, '2026-07-17 12:55:00'),
            $second->id => $this->positionData($second, 25, 25, '2026-07-17 12:55:00'),
        ]);

        $mock = Mockery::mock(ForeignProjectGeofenceMonitoringService::class);
        $mock->shouldReceive('processUnitPosition')->once()->andThrow(new \RuntimeException('unit failed'));
        $mock->shouldReceive('processUnitPosition')->once()->andReturn(null);
        $this->app->instance(ForeignProjectGeofenceMonitoringService::class, $mock);

        $this->assertCommandOutputContains('fleet:monitor-foreign-geofences', [
            'positions: 2',
            'processed: 1',
            'failed: 1',
        ]);
    }

    public function test_position_source_is_called_by_batch(): void
    {
        [$home] = $this->projectsWithGeofences();
        $first = $this->equipment($home);
        $second = $this->equipment($home);
        $third = $this->equipment($home);

        $source = new FakePositionSource([
            $first->id => $this->positionData($first, 25, 25, '2026-07-17 12:55:00'),
            $second->id => $this->positionData($second, 25, 25, '2026-07-17 12:55:00'),
            $third->id => $this->positionData($third, 25, 25, '2026-07-17 12:55:00'),
        ]);
        $this->app->instance(UnitPositionSource::class, $source);

        $this->assertCommandOutputContains('fleet:monitor-foreign-geofences', [
            'batches: 2',
            'positions: 3',
            'processed: 3',
        ]);

        $this->assertSame(2, $source->calls);
    }

    public function test_system_position_source_failure_returns_failure_exit_code(): void
    {
        [$home] = $this->projectsWithGeofences();
        $this->equipment($home);
        $this->app->bind(UnitPositionSource::class, FailingPositionSource::class);

        $this->assertSame(1, Artisan::call('fleet:monitor-foreign-geofences'));
        $this->assertStringContainsString('Position source should not be called.', Artisan::output());
    }

    public function test_scheduler_registration_is_guarded_by_feature_flag(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($schedule);
        $this->assertStringContainsString("config('fleet.foreign_geofence.monitoring_enabled'", $schedule);
        $this->assertStringContainsString("Schedule::command('fleet:monitor-foreign-geofences')", $schedule);
        $this->assertStringContainsString("config('fleet.foreign_geofence.monitoring_interval_minutes'", $schedule);
        $this->assertStringContainsString('->timezone(config(\'app.timezone\'))', $schedule);
        $this->assertStringContainsString("config('fleet.foreign_geofence.monitoring_lock_seconds'", $schedule);
        $this->assertStringNotContainsString('runInBackground', $schedule);
    }

    public function test_scheduler_is_not_registered_while_feature_flag_is_disabled(): void
    {
        config(['fleet.foreign_geofence.monitoring_enabled' => false]);

        $this->assertSame(0, Artisan::call('schedule:list'));
        $this->assertStringNotContainsString('fleet:monitor-foreign-geofences', Artisan::output());
    }

    public function test_production_entry_point_calls_monitoring_service(): void
    {
        $command = file_get_contents(app_path('Console/Commands/MonitorForeignGeofences.php'));

        $this->assertIsString($command);
        $this->assertStringContainsString('processUnitPosition', $command);
    }

    private function bindPositions(array $positions): void
    {
        $this->app->instance(UnitPositionSource::class, new FakePositionSource($positions));
    }

    private function assertCommandOutputContains(string $command, array $expectedOutput): void
    {
        $this->assertSame(0, Artisan::call($command));

        $output = Artisan::output();

        foreach ($expectedOutput as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    private function projectsWithGeofences(): array
    {
        $home = Project::query()->create(['name' => 'Home', 'active' => true]);
        $foreign = Project::query()->create(['name' => 'Foreign', 'active' => true]);

        Geofence::query()->create([
            'project_id' => $home->id,
            'name' => 'Home zone',
            'wialon_geofence_id' => 'home',
            'geometry_json' => $this->polygon(0, 0, 10, 10),
            'active' => true,
        ]);
        Geofence::query()->create([
            'project_id' => $foreign->id,
            'name' => 'Foreign zone',
            'wialon_geofence_id' => 'foreign',
            'geometry_json' => $this->polygon(20, 20, 30, 30),
            'active' => true,
        ]);

        return [$home, $foreign];
    }

    private function equipment(Project $project): Equipment
    {
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Excavator']);

        return Equipment::query()->create([
            'name' => 'Unit '.uniqid(),
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'matched_wialon_group_id' => '601701935',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
            'planned_daily_hours' => 10,
            'active' => true,
        ]);
    }

    private function positionData(Equipment $unit, float $lng, float $lat, string $time): UnitPositionData
    {
        return new UnitPositionData($unit->id, $unit->wialon_unit_id, $lat, $lng, $time);
    }

    private function position(float $lng, float $lat, string $time): array
    {
        return [
            'lat' => $lat,
            'lng' => $lng,
            'speed' => 0,
            'time' => $time,
        ];
    }

    private function polygon(float $minLng, float $minLat, float $maxLng, float $maxLat): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$minLng, $minLat],
                [$maxLng, $minLat],
                [$maxLng, $maxLat],
                [$minLng, $maxLat],
                [$minLng, $minLat],
            ]],
        ];
    }
}

class FakePositionSource implements UnitPositionSource
{
    public int $calls = 0;

    public function __construct(private array $positions) {}

    public function latestPositionsFor(Collection $equipment): array
    {
        $this->calls++;

        return $equipment
            ->mapWithKeys(fn (Equipment $unit): array => isset($this->positions[$unit->id]) ? [$unit->id => $this->positions[$unit->id]] : [])
            ->all();
    }
}

class FailingPositionSource implements UnitPositionSource
{
    public function latestPositionsFor(Collection $equipment): array
    {
        throw new \RuntimeException('Position source should not be called.');
    }
}
