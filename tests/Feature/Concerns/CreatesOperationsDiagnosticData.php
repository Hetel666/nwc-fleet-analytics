<?php

namespace Tests\Feature\Concerns;

use App\Models\DailyUnitAggregate;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\Setting;
use App\Models\UnitForeignGeofenceInterval;
use App\Services\DashboardDataVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

trait CreatesOperationsDiagnosticData
{
    /**
     * @return array<string, mixed>
     */
    private function runDiagnosticCommand(string $command): array
    {
        $exitCode = Artisan::call($command, ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($exitCode, $payload['exit_code']);
        $this->assertSame($command, $payload['command']);
        $this->assertContains($payload['status'], ['ok', 'warn', 'fail']);
        $this->assertContains($payload['status_label'], ['ready', 'warning', 'failed']);
        $this->assertIsString($payload['timestamp']);
        $this->assertIsArray($payload['summary']);
        $this->assertArrayHasKey('ok', $payload['summary']);
        $this->assertArrayHasKey('warnings', $payload['summary']);
        $this->assertArrayHasKey('critical', $payload['summary']);
        $this->assertIsArray($payload['checks']);
        $this->assertDoesNotMatchRegularExpression('/\e\[[0-9;]*m/', $output);

        return [
            'exit_code' => $exitCode,
            'payload' => $payload,
        ];
    }

    private function assertNoCriticalFailures(string $command): void
    {
        $result = $this->runDiagnosticCommand($command);

        $this->assertLessThan(2, $result['exit_code']);
    }

    private function seedOperationsDiagnosticData(): Equipment
    {
        $project = Project::create([
            'name' => 'Yuxari Sirvan LOT3',
            'active' => true,
        ]);

        $foreignProject = Project::create([
            'name' => 'Fuzuli Agdam yol',
            'active' => true,
        ]);

        $wialonGroup = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'Yuxari Sirvan LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $type = EquipmentType::create(['name' => 'Excavator']);

        $equipment = Equipment::create([
            'name' => '10-AD-410',
            'registration_number' => '10-AD-410',
            'wialon_unit_id' => '600000001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $wialonGroup->id,
            'matched_wialon_group_id' => $wialonGroup->wialon_group_id,
            'matched_wialon_group_name' => $wialonGroup->name,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
            'active' => true,
            'excluded_from_dashboard' => false,
            'last_synced_at' => now(),
            'last_position_json' => [
                'lat' => 40.1,
                'lon' => 47.1,
                'time' => now()->subMinutes(5)->toDateTimeString(),
                'received_at' => now()->subMinutes(4)->toDateTimeString(),
            ],
        ]);

        EquipmentDailyStat::create([
            'stat_date' => now()->toDateString(),
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 8.5,
            'distance_km' => 12.4,
            'utilization_percent' => 85,
            'calculation_source' => 'test',
            'calculation_status' => 'ok',
        ]);

        DailyUnitAggregate::create([
            'date' => now()->toDateString(),
            'unit_id' => $equipment->wialon_unit_id,
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'equipment_type_id' => $type->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'engine_hours' => 8.5,
            'mileage' => 12.4,
            'geofence_outside_hours' => 0,
        ]);

        $homeGeofence = Geofence::create([
            'name' => 'Yuxari Sirvan LOT3',
            'project_id' => $project->id,
            'wialon_geofence_id' => '601701680:187',
            'active' => true,
        ]);

        $foreignGeofence = Geofence::create([
            'name' => 'Fuzuli Agdam yol',
            'project_id' => $foreignProject->id,
            'wialon_geofence_id' => '601701680:185',
            'active' => true,
        ]);

        UnitForeignGeofenceInterval::create([
            'unit_id' => $equipment->id,
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'home_project_id' => $project->id,
            'home_project_name' => $project->name,
            'home_geofence_id' => $homeGeofence->id,
            'foreign_project_id' => $foreignProject->id,
            'foreign_project_name' => $foreignProject->name,
            'foreign_geofence_id' => $foreignGeofence->id,
            'foreign_geofence_name' => $foreignGeofence->name,
            'entered_at' => now()->subHours(4),
            'last_position_at' => now()->subMinutes(5),
            'duration_seconds' => 14400,
            'status' => UnitForeignGeofenceInterval::STATUS_OPEN,
            'source' => 'test',
            'calculated_at' => now(),
        ]);

        Setting::updateOrCreate(['key' => 'auto_sync_units_last_status'], [
            'value' => 'success',
            'is_secret' => false,
        ]);

        Setting::updateOrCreate(['key' => 'auto_sync_daily_last_status'], [
            'value' => 'success',
            'is_secret' => false,
        ]);

        Cache::forever(DashboardDataVersion::KEY, 1);

        return $equipment;
    }
}
