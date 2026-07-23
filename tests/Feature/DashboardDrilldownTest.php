<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardDrilldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_drilldown_requires_authentication(): void
    {
        $this->getJson(route('dashboard.drilldown.units'))
            ->assertUnauthorized();
    }

    public function test_drilldown_filters_by_ownership_and_equipment_type(): void
    {
        $user = $this->user();
        $project = Project::query()->create(['name' => 'Yuxarı Şirvan LOT3', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);

        $target = Equipment::query()->create([
            'name' => 'NWC Dump 01',
            'registration_number' => '90-AA-001',
            'wialon_unit_id' => '1001',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701930',
            'active' => true,
        ]);

        Equipment::query()->create([
            'name' => 'ICARE Dump 01',
            'registration_number' => '90-AA-002',
            'wialon_unit_id' => '1002',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'matched_wialon_group_id' => '601701936',
            'active' => true,
        ]);

        Equipment::query()->create([
            'name' => 'NWC Excavator 01',
            'registration_number' => '90-AA-003',
            'wialon_unit_id' => '1003',
            'equipment_type_id' => $excavator->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701930',
            'active' => true,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $target->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 4,
            'distance_km' => 20,
            'last_message_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-15',
                'date_to' => '2026-07-15',
                'ownership' => 'nwc',
                'equipment_type_id' => $dumpTruck->id,
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'NWC Dump 01')
            ->assertJsonMissing(['name' => 'ICARE Dump 01'])
            ->assertJsonMissing(['name' => 'NWC Excavator 01']);
    }

    public function test_drilldown_export_uses_same_filters(): void
    {
        $user = $this->user();
        $project = Project::query()->create(['name' => 'Ağdam Azərsu', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Loader']);

        Equipment::query()->create([
            'name' => 'Loader 01',
            'registration_number' => '10-AA-001',
            'wialon_unit_id' => '2001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'matched_wialon_group_id' => '601701958',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.drilldown.units.export', [
                'ownership' => 'icare',
                'equipment_type_id' => $type->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_drilldown_filters_by_work_category(): void
    {
        $user = $this->user();
        $project = Project::query()->create(['name' => 'Fuzuli Agdam yol', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Excavator']);

        $overtime = Equipment::query()->create([
            'name' => 'Overtime Excavator',
            'wialon_unit_id' => '3001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        $normal = Equipment::query()->create([
            'name' => 'Normal Excavator',
            'wialon_unit_id' => '3002',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        DB::table('equipment_daily_stats')->insert([
            'stat_date' => '2026-07-15',
            'equipment_id' => $overtime->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 6,
            'distance_km' => 20,
            'first_message_at' => '2026-07-15 11:00:00',
            'last_message_at' => '2026-07-15 20:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('equipment_daily_stats')->insert([
            'stat_date' => '2026-07-15',
            'equipment_id' => $normal->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 6,
            'distance_km' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-15',
                'date_to' => '2026-07-15',
                'ownership' => 'nwc',
                'work_category' => 'overtime',
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'Overtime Excavator')
            ->assertJsonMissing(['name' => 'Normal Excavator']);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_VIEWER,
            'active' => true,
        ]);
    }
}
