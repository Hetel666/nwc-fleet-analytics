<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\StatisticBackfillItem;
use App\Models\UnitForeignGeofenceInterval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_foundation_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasColumns('equipments', [
            'project_wialon_group_id',
            'matched_wialon_group_id',
            'matched_wialon_group_name',
            'excluded_from_dashboard',
            'dashboard_exclusion_reason',
        ]));

        $this->assertTrue(Schema::hasColumn('geofences', 'normalized_name'));
        $this->assertTrue(Schema::hasColumn('project_wialon_groups', 'is_active'));
        $this->assertTrue(Schema::hasTable('statistic_backfill_items'));
        $this->assertTrue(Schema::hasTable('historical_recalculations'));
        $this->assertTrue(Schema::hasTable('historical_recalculation_tasks'));
        $this->assertTrue(Schema::hasTable('unit_foreign_geofence_intervals'));
    }

    public function test_foundation_models_can_be_created_with_nullable_intermediate_state(): void
    {
        $project = Project::create([
            'name' => 'Yuxari Sirvan LOT3',
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
            'wialon_unit_id' => '600000001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $wialonGroup->id,
            'matched_wialon_group_id' => '601701935',
            'matched_wialon_group_name' => 'Yuxari Sirvan LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
            'active' => true,
            'excluded_from_dashboard' => false,
        ]);

        $geofence = Geofence::create([
            'name' => 'Yuxari Sirvan LOT3',
            'normalized_name' => 'yuxari sirvan lot3',
            'project_id' => $project->id,
            'wialon_geofence_id' => '601701680:187',
            'geometry_json' => null,
            'active' => true,
        ]);

        $backfill = StatisticBackfillItem::create([
            'stat_date' => '2026-07-21',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->assertTrue($backfill->stat_date->isSameDay('2026-07-21'));
        $this->assertSame($project->id, $backfill->project->id);
        $this->assertTrue($equipment->excluded_from_dashboard === false);
        $this->assertSame($wialonGroup->id, $equipment->projectWialonGroup->id);
        $this->assertTrue($wialonGroup->is_active);
        $this->assertSame($project->id, $geofence->project->id);
    }

    public function test_historical_recalculation_relations_and_casts_work(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin-foundation@example.com',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);

        $project = Project::create([
            'name' => 'Fuzuli Agdam yol',
            'active' => true,
        ]);

        $run = HistoricalRecalculation::create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'signature' => hash('sha256', 'foundation'),
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-07',
            'project_ids' => [$project->id],
            'requested_by' => $user->id,
        ]);

        $task = HistoricalRecalculationTask::create([
            'historical_recalculation_id' => $run->id,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-01',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->assertSame('uuid', $run->getRouteKeyName());
        $this->assertTrue($run->date_from->isSameDay('2026-07-01'));
        $this->assertSame([$project->id], $run->project_ids);
        $this->assertSame($user->id, $run->requestedBy->id);
        $this->assertSame($run->id, $task->run->id);
        $this->assertSame($project->id, $task->project->id);
    }

    public function test_foreign_geofence_interval_relations_and_casts_work(): void
    {
        $homeProject = Project::create([
            'name' => 'Lacin yol',
            'active' => true,
        ]);
        $foreignProject = Project::create([
            'name' => 'Kalbacar yol',
            'active' => true,
        ]);
        $type = EquipmentType::create(['name' => 'Loader']);
        $unit = Equipment::create([
            'name' => '90-HL-963',
            'wialon_unit_id' => '600000002',
            'equipment_type_id' => $type->id,
            'project_id' => $homeProject->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
            'active' => true,
        ]);
        $homeGeofence = Geofence::create([
            'name' => 'Lacin yol',
            'project_id' => $homeProject->id,
            'active' => true,
        ]);
        $foreignGeofence = Geofence::create([
            'name' => 'Kalbacar yol',
            'project_id' => $foreignProject->id,
            'active' => true,
        ]);

        $interval = UnitForeignGeofenceInterval::create([
            'unit_id' => $unit->id,
            'wialon_unit_id' => $unit->wialon_unit_id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'home_project_id' => $homeProject->id,
            'home_geofence_id' => $homeGeofence->id,
            'home_geofence_ids_json' => ['601701680:41'],
            'foreign_project_id' => $foreignProject->id,
            'foreign_geofence_id' => $foreignGeofence->id,
            'entered_at' => '2026-07-21 08:00:00',
            'last_position_at' => '2026-07-21 12:00:00',
            'status' => UnitForeignGeofenceInterval::STATUS_OPEN,
            'duration_seconds' => 14400,
            'project_mismatch' => true,
        ]);

        $this->assertSame($unit->id, $interval->unit->id);
        $this->assertSame($homeProject->id, $interval->homeProject->id);
        $this->assertSame($foreignProject->id, $interval->foreignProject->id);
        $this->assertSame($homeGeofence->id, $interval->homeGeofence->id);
        $this->assertSame($foreignGeofence->id, $interval->foreignGeofence->id);
        $this->assertTrue($interval->entered_at->isSameDay('2026-07-21'));
        $this->assertSame(['601701680:41'], $interval->home_geofence_ids_json);
        $this->assertTrue($interval->project_mismatch);
        $this->assertSame(14400, $interval->duration_seconds);
    }
}
