<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\GeofenceViolationReportRow;
use App\Models\Project;
use App\Services\GeofenceViolationReportImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceViolationReportImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_idempotent_and_preserves_report_duration_without_geospatial_calculation(): void
    {
        $equipment = $this->equipment();
        $importer = app(GeofenceViolationReportImporter::class);
        $row = [
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'equipment_name' => $equipment->name,
            'exited_at' => '2026-07-27 10:00:00',
            'last_confirmed_at' => '2026-07-27 13:00:01',
            'outside_duration_seconds' => 10_801,
            'last_project_geofence' => 'Füzuli Ağdam yol',
            'last_location' => '40.4093, 49.8671',
            'is_active' => true,
        ];

        $this->assertSame(['imported' => 1, 'rejected' => 0], $importer->import([$row]));
        $this->assertSame(['imported' => 1, 'rejected' => 0], $importer->import([
            [
                ...$row,
                'last_confirmed_at' => '2026-07-27 14:00:00',
                'outside_duration_seconds' => 14_400,
                'is_active' => false,
                'ended_at' => '2026-07-27 14:00:00',
            ],
        ]));

        $this->assertDatabaseCount('geofence_violation_report_rows', 1);
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'report_name' => GeofenceViolationReportRow::REPORT_NAME,
            'equipment_id' => $equipment->id,
            'equipment_type' => 'Excavator',
            'outside_duration_seconds' => 14_400,
            'is_active' => false,
        ]);
    }

    public function test_rows_without_valid_confirmed_report_times_are_rejected(): void
    {
        $equipment = $this->equipment();
        $result = app(GeofenceViolationReportImporter::class)->import([
            [
                'wialon_unit_id' => $equipment->wialon_unit_id,
                'exited_at' => '2026-07-27 10:00:00',
                'last_confirmed_at' => null,
                'outside_duration_seconds' => 14_400,
            ],
            [
                'wialon_unit_id' => $equipment->wialon_unit_id,
                'exited_at' => '2026-07-27 14:00:00',
                'last_confirmed_at' => '2026-07-27 13:00:00',
                'outside_duration_seconds' => 14_400,
            ],
            [
                'wialon_unit_id' => $equipment->wialon_unit_id,
                'exited_at' => '2026-07-27 10:00:00',
                'last_confirmed_at' => '2026-07-27 14:00:00',
                'outside_duration_seconds' => 7_200,
            ],
        ]);

        $this->assertSame(['imported' => 0, 'rejected' => 3], $result);
        $this->assertDatabaseCount('geofence_violation_report_rows', 0);
    }

    public function test_console_import_rejects_documents_from_another_report(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geofence-report-');
        file_put_contents($path, json_encode([
            'report_name' => 'Another report',
            'rows' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('geofence-violations:import', ['file' => $path])
                ->expectsOutput('The JSON document is not from the Geofence Pozuntuları api report.')
                ->assertFailed();
        } finally {
            @unlink($path);
        }
    }

    private function equipment(): Equipment
    {
        $project = Project::create(['name' => 'Füzuli Ağdam yol', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);

        return Equipment::create([
            'name' => '10-AF-100',
            'wialon_unit_id' => '601701680',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
    }
}
