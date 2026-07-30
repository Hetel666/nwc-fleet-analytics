<?php

namespace Tests\Feature;

use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardDailyAverageService;
use App\Services\DashboardService;
use App\Services\FleetEfficiencyService;
use App\Services\TopWorkingUnitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardUnassignedProjectVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_layihesiz_is_visible_in_operational_and_share_widgets(): void
    {
        config(['fleet.dashboard.cache_minutes' => 0]);

        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $unassignedProject = Project::query()->create(['name' => Project::DASHBOARD_UNASSIGNED_NAMES[0], 'active' => true]);
        $projectGroup = $this->group($project, '601701001');
        $unassignedGroup = $this->group($unassignedProject, '601701002');
        $projectUnit = $this->equipment($project, $projectGroup, $type, 'Project unit', '1001');
        $unassignedUnit = $this->equipment($unassignedProject, $unassignedGroup, $type, 'Unassigned unit', '1002');

        $this->dailyStat($projectUnit, '2026-07-25', 5.0);
        $this->dailyStat($unassignedUnit, '2026-07-25', 20.0);
        $this->engineReportRow($projectUnit, '2026-07-25', 5.0);
        $this->engineReportRow($unassignedUnit, '2026-07-25', 20.0);

        $filters = ['date_from' => '2026-07-25', 'date_to' => '2026-07-25'];
        $dashboard = app(DashboardService::class)->getDashboard($filters);
        $ownershipShare = collect($dashboard['overview']['ownership_share'])->keyBy('label');

        $this->assertSame(25.0, $dashboard['overview']['total_hours']);
        $this->assertSame(2, $dashboard['overview']['equipment_count']);
        $this->assertSame(2, $ownershipShare[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(2, collect($dashboard['equipmentTypesByOwnership'][Equipment::OWNERSHIP_NWC])->firstWhere('name', 'Excavator')['total']);
        $this->assertSame(1.0, collect($dashboard['projectOwnershipComparison'])->firstWhere('name', $project->name)[Equipment::OWNERSHIP_NWC]);
        $this->assertSame(1.0, collect($dashboard['projectOwnershipComparison'])->firstWhere('name', $unassignedProject->name)[Equipment::OWNERSHIP_NWC]);

        $averageRows = app(DashboardDailyAverageService::class)->typeSummary($filters, 'engine_hours');
        $excavatorAverage = $averageRows
            ->where('vehicle_type', 'Excavator')
            ->where('ownership', Equipment::OWNERSHIP_NWC)
            ->first();

        $this->assertSame(2, $excavatorAverage['units_count']);
        $this->assertSame(25.0, $excavatorAverage['total_value']);
        $this->assertSame(1, app(FleetEfficiencyService::class)->summaryForOwnership($filters, Equipment::OWNERSHIP_NWC)[FleetEfficiencyService::DAY_STATUS_LESS_THAN_7]);
        $this->assertSame(
            ['Unassigned unit', 'Project unit'],
            array_column(app(TopWorkingUnitsService::class)->most($filters, 20), 'unit_name')
        );
    }

    public function test_repair_project_is_visible_in_all_dashboard_widgets_and_exports(): void
    {
        config(['fleet.dashboard.cache_minutes' => 0]);

        $type = EquipmentType::query()->create(['name' => 'Excavator']);
        $project = Project::query()->create(['name' => 'Project A', 'active' => true]);
        $repairProject = Project::query()->create(['name' => 'Təmir', 'active' => true]);
        $projectGroup = $this->group($project, '601701101');
        $repairNwcGroup = $this->group($repairProject, '601701102');
        $repairIcareGroup = ProjectWialonGroup::query()->create([
            'project_id' => $repairProject->id,
            'wialon_group_id' => '601701103',
            'name' => 'Təmir - İcarə',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'is_active' => true,
        ]);

        $projectUnit = $this->equipment($project, $projectGroup, $type, 'Project unit', '1101');
        $repairNwcUnit = $this->equipment($repairProject, $repairNwcGroup, $type, 'Repair NWC unit', '1102');
        $repairIcareUnit = $this->equipment($repairProject, $repairIcareGroup, $type, 'Repair ICARE unit', '1103');

        foreach ([$projectUnit, $repairNwcUnit, $repairIcareUnit] as $index => $unit) {
            $hours = 5.0 + $index;
            $this->dailyStat($unit, '2026-07-25', $hours);
            $this->engineReportRow($unit, '2026-07-25', $hours);
        }

        $filters = ['date_from' => '2026-07-25', 'date_to' => '2026-07-25'];
        $dashboardService = app(DashboardService::class);
        $dashboard = $dashboardService->getDashboard($filters);
        $ownershipShare = collect($dashboard['overview']['ownership_share'])->keyBy('label');

        $this->assertSame(18.0, $dashboard['overview']['total_hours']);
        $this->assertSame(3, $dashboard['overview']['equipment_count']);
        $this->assertSame(2, $ownershipShare[Equipment::OWNERSHIP_NWC]['count']);
        $this->assertSame(1, $ownershipShare[Equipment::OWNERSHIP_ICARE]['count']);
        $this->assertSame(2, collect($dashboard['equipmentTypesByOwnership'][Equipment::OWNERSHIP_NWC])->firstWhere('name', 'Excavator')['total']);
        $this->assertSame(1, collect($dashboard['equipmentTypesByOwnership'][Equipment::OWNERSHIP_ICARE])->firstWhere('name', 'Excavator')['total']);

        $repairComparison = collect($dashboard['projectOwnershipComparison'])->firstWhere('name', 'Təmir');
        $this->assertSame(1.0, $repairComparison[Equipment::OWNERSHIP_NWC]);
        $this->assertSame(1.0, $repairComparison[Equipment::OWNERSHIP_ICARE]);

        $this->assertSame(3, app(DashboardDailyAverageService::class)->typeSummary($filters, 'engine_hours')->sum('units_count'));
        $this->assertSame(2, app(FleetEfficiencyService::class)->summaryForOwnership($filters, Equipment::OWNERSHIP_NWC)[FleetEfficiencyService::DAY_STATUS_LESS_THAN_7]);
        $this->assertSame(
            ['Repair ICARE unit', 'Repair NWC unit', 'Project unit'],
            array_column(app(TopWorkingUnitsService::class)->most($filters, 20), 'unit_name')
        );

        $overviewRows = $dashboardService->getDashboardExport($filters, 'overview')['sections'][1]['rows'];
        $ownershipRows = $dashboardService->getDashboardExport($filters, 'ownership-share')['sections'][1]['rows'];
        $typeRows = $dashboardService->getDashboardExport($filters, 'equipment-types')['sections'][1]['rows'];
        $nwcTypeRows = $dashboardService->getDashboardExport($filters, 'equipment-types-nwc')['sections'][1]['rows'];
        $icareTypeRows = $dashboardService->getDashboardExport($filters, 'equipment-types-icare')['sections'][1]['rows'];
        $projectComparisonRows = $dashboardService->getDashboardExport($filters, 'project-comparison')['sections'][1]['rows'];

        $this->assertContains('Təmir', array_column($overviewRows, 1));
        $this->assertContains('Təmir', array_column($typeRows, 4));
        $this->assertContains('Təmir', array_column($ownershipRows, 1));
        $this->assertContains('Təmir', array_column($nwcTypeRows, 4));
        $this->assertContains('Təmir', array_column($icareTypeRows, 4));
        $this->assertContains('Təmir', array_column($projectComparisonRows, 1));
    }

    private function group(Project $project, string $wialonGroupId): ProjectWialonGroup
    {
        return ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => $wialonGroupId,
            'name' => $project->name.' - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
    }

    private function equipment(Project $project, ProjectWialonGroup $group, EquipmentType $type, string $name, string $wialonId): Equipment
    {
        return Equipment::query()->create([
            'name' => $name,
            'registration_number' => $wialonId,
            'wialon_unit_id' => $wialonId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => $group->wialon_group_id,
            'ownership_type' => $group->ownership_type,
            'active' => true,
        ]);
    }

    private function dailyStat(Equipment $equipment, string $date, float $hours): void
    {
        EquipmentDailyStat::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'daytime_hours' => $hours,
            'overtime_hours' => 0,
            'total_hours' => $hours,
            'day_status' => FleetEfficiencyService::DAY_STATUS_LESS_THAN_7,
            'has_overtime' => false,
            'data_available' => true,
            'daytime_data_available' => true,
            'overtime_data_available' => true,
            'distance_km' => $hours,
            'calculation_source' => 'wialon_shift_report',
            'calculation_status' => 'ok',
        ]);
    }

    private function engineReportRow(Equipment $equipment, string $date, float $hours): void
    {
        EngineHoursReportUnitDay::query()->create([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'equipment_type_id' => $equipment->equipment_type_id,
            'ownership_type' => $equipment->ownership_type,
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'unit_name' => $equipment->name,
            'vehicle_type' => $equipment->type?->name,
            'engine_hours' => $hours,
            'engine_hours_source' => EngineHoursReportUnitDay::SOURCE,
            'parse_status' => 'ok',
            'source_group_ids_json' => [$equipment->matched_wialon_group_id],
            'synced_at' => now(),
        ]);
    }
}
