<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $overtime->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 6,
            'overtime_hours' => 1.2,
            'distance_km' => 20,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $normal->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 6,
            'distance_km' => 10,
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

    public function test_efficiency_drilldown_accepts_extended_filters(): void
    {
        $user = $this->user();
        $project = Project::query()->create(['name' => 'Laçın yol', 'active' => true]);
        $otherProject = Project::query()->create(['name' => 'Xocavənd təlim mərkəzi', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $target = Equipment::query()->create([
            'name' => 'Filtered Loader',
            'registration_number' => '90-FL-001',
            'wialon_unit_id' => '70001',
            'equipment_type_id' => $loader->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        $other = Equipment::query()->create([
            'name' => 'Other Loader',
            'registration_number' => '90-FL-002',
            'wialon_unit_id' => '70002',
            'equipment_type_id' => $loader->id,
            'project_id' => $otherProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $target->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 0.5,
            'daytime_hours' => 0.5,
            'overtime_hours' => 0,
            'total_hours' => 0.5,
            'day_status' => 'less_than_1_hour',
            'has_overtime' => false,
            'data_available' => true,
            'daytime_data_available' => true,
            'overtime_data_available' => true,
            'distance_km' => 10,
            'calculation_source' => 'wialon_shift_report',
            'calculation_status' => 'ok',
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $other->id,
            'project_id' => $otherProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 0.5,
            'daytime_hours' => 0.5,
            'overtime_hours' => 0,
            'total_hours' => 0.5,
            'day_status' => 'less_than_1_hour',
            'has_overtime' => false,
            'data_available' => true,
            'daytime_data_available' => true,
            'overtime_data_available' => true,
            'distance_km' => 10,
            'calculation_source' => 'wialon_shift_report',
            'calculation_status' => 'ok',
        ]);

        $query = [
            'date_from' => '2026-07-15',
            'date_to' => '2026-07-15',
            'ownership' => 'nwc',
            'work_category' => 'less_than_1',
            'day_status' => 'less_than_1',
            'project_ids' => [$project->id],
            'vehicle_types' => ['loader'],
            'data_status' => 'available',
            'has_overtime' => 'no',
            'day_hours_min' => 0,
            'day_hours_max' => 1,
            'search' => '70001',
            'sort' => 'date',
            'direction' => 'asc',
            'per_page' => 20,
        ];

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', $query))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'Filtered Loader')
            ->assertJsonMissing(['name' => 'Other Loader']);

        $this->actingAs($user)
            ->get(route('dashboard.drilldown.units.export', $query))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_efficiency_modal_keeps_project_ownership_status_and_period_context(): void
    {
        $user = $this->user();
        $lot3 = Project::query()->create(['name' => 'Yuxari Shirvan LOT3', 'active' => true]);
        $otherProject = Project::query()->create(['name' => 'Other project', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $target = Equipment::query()->create([
            'name' => 'LOT3 NWC less than one',
            'registration_number' => '90-LT-001',
            'wialon_unit_id' => '91001',
            'equipment_type_id' => $loader->id,
            'project_id' => $lot3->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        $wrongOwnership = Equipment::query()->create([
            'name' => 'LOT3 ICARE less than one',
            'wialon_unit_id' => '91002',
            'equipment_type_id' => $loader->id,
            'project_id' => $lot3->id,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'matched_wialon_group_id' => '601701936',
            'active' => true,
        ]);

        $wrongProject = Equipment::query()->create([
            'name' => 'Other project NWC less than one',
            'wialon_unit_id' => '91003',
            'equipment_type_id' => $loader->id,
            'project_id' => $otherProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        $wrongStatus = Equipment::query()->create([
            'name' => 'LOT3 NWC regular',
            'wialon_unit_id' => '91004',
            'equipment_type_id' => $loader->id,
            'project_id' => $lot3->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        $wrongDateStatus = Equipment::query()->create([
            'name' => 'LOT3 NWC previous date less than one',
            'wialon_unit_id' => '91005',
            'equipment_type_id' => $loader->id,
            'project_id' => $lot3->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        foreach ([
            [$target, '2026-07-19', 0.5],
            [$wrongOwnership, '2026-07-19', 0.5],
            [$wrongProject, '2026-07-19', 0.5],
            [$wrongStatus, '2026-07-19', 6],
            [$wrongDateStatus, '2026-07-18', 0.5],
            [$wrongDateStatus, '2026-07-19', 6],
        ] as [$unit, $date, $hours]) {
            $dayStatus = $hours < 1 ? 'less_than_1_hour' : ($hours < 7 ? 'less_than_7_hours' : 'between_7_and_10_hours');

            EquipmentDailyStat::query()->create([
                'stat_date' => $date,
                'equipment_id' => $unit->id,
                'project_id' => $unit->project_id,
                'ownership_type' => $unit->ownership_type,
                'worked_hours' => $hours,
                'daytime_hours' => $hours,
                'overtime_hours' => 0,
                'total_hours' => $hours,
                'day_status' => $dayStatus,
                'has_overtime' => false,
                'data_available' => true,
                'daytime_data_available' => true,
                'overtime_data_available' => true,
                'distance_km' => 10,
                'calculation_source' => 'wialon_shift_report',
                'calculation_status' => 'ok',
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $lot3->id,
                'ownership' => 'nwc',
                'status' => 'less_than_1',
                'work_category' => 'less_than_1',
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'LOT3 NWC less than one')
            ->assertJsonPath('data.0.ownership', 'NWC')
            ->assertJsonPath('data.0.project', 'Yuxari Shirvan LOT3')
            ->assertJsonPath('data.0.daytime_status_label', '1 saatdan az işləyən')
            ->assertJsonMissing(['name' => 'LOT3 ICARE less than one'])
            ->assertJsonMissing(['name' => 'Other project NWC less than one'])
            ->assertJsonMissing(['name' => 'LOT3 NWC regular'])
            ->assertJsonMissing(['name' => 'LOT3 NWC previous date less than one']);
    }

    public function test_efficiency_modal_uses_backend_pagination_without_losing_total(): void
    {
        $user = $this->user();
        $project = Project::query()->create(['name' => 'Paged project', 'active' => true]);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        for ($index = 1; $index <= 15; $index++) {
            $unit = Equipment::query()->create([
                'name' => sprintf('Paged Loader %02d', $index),
                'wialon_unit_id' => (string) (92000 + $index),
                'equipment_type_id' => $loader->id,
                'project_id' => $project->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'matched_wialon_group_id' => '601701903',
                'active' => true,
            ]);

            EquipmentDailyStat::query()->create([
                'stat_date' => '2026-07-19',
                'equipment_id' => $unit->id,
                'project_id' => $project->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'worked_hours' => 0.5,
                'daytime_hours' => 0.5,
                'overtime_hours' => 0,
                'total_hours' => 0.5,
                'day_status' => 'less_than_1_hour',
                'has_overtime' => false,
                'data_available' => true,
                'daytime_data_available' => true,
                'overtime_data_available' => true,
                'calculation_source' => 'wialon_shift_report',
                'calculation_status' => 'ok',
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-19',
                'date_to' => '2026-07-19',
                'project_id' => $project->id,
                'ownership' => 'nwc',
                'work_category' => 'less_than_1',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 15)
            ->assertJsonCount(10, 'data');
    }

    public function test_average_metric_drilldown_returns_formula_for_selected_vehicle_type(): void
    {
        $user = $this->user();
        $project = Project::query()->create(['name' => 'Laçın yol', 'active' => true]);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $target = Equipment::query()->create([
            'name' => 'Excavator 01',
            'registration_number' => '90-EX-001',
            'wialon_unit_id' => '80001',
            'equipment_type_id' => $excavator->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        $other = Equipment::query()->create([
            'name' => 'Loader 01',
            'registration_number' => '90-LD-001',
            'wialon_unit_id' => '80002',
            'equipment_type_id' => $loader->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $target->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 8,
            'distance_km' => 20,
        ]);

        EquipmentDailyStat::query()->create([
            'stat_date' => '2026-07-15',
            'equipment_id' => $other->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 20,
            'distance_km' => 40,
        ]);

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-15',
                'date_to' => '2026-07-15',
                'ownership' => 'nwc',
                'metric' => 'engine_hours',
                'vehicle_types' => ['excavator'],
                'sort' => 'engine_hours',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.average_formula.vehicle_type', 'Excavator')
            ->assertJsonPath('summary.average_formula.average_value', '8.00 saat')
            ->assertJsonPath('data.0.name', 'Excavator 01')
            ->assertJsonMissing(['name' => 'Loader 01']);

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-15',
                'date_to' => '2026-07-15',
                'ownership' => 'nwc',
                'metric' => 'engine_hours',
                'vehicle_types' => ['excavator'],
                'group_by' => 'day',
            ]))
            ->assertOk()
            ->assertJsonPath('columns.total_value', 'Ümumi motosaat')
            ->assertJsonPath('data.0.total_value', '8.00 saat')
            ->assertJsonPath('data.0.units_count', 1);
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
