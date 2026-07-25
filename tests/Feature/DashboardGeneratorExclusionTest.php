<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGeneratorExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_excluded_units_are_removed_from_dashboard_aggregates_rankings_events_and_export(): void
    {
        $project = Project::create(['name' => 'Dashboard exclusion project', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Dashboard exclusion project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701922',
            'name' => 'Dashboard exclusion project - ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $excavator = EquipmentType::create(['name' => 'Excavator']);
        $dumpTruck = EquipmentType::create(['name' => 'Dump Truck']);
        $emptyElectricGenerator = EquipmentType::create(['name' => 'Empty Electric Generator']);

        $nwcExcavator = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'NWC Excavator');
        $icareDumpTruck = $this->equipment($project, $dumpTruck, Equipment::OWNERSHIP_ICARE, 'ICARE Dump Truck');
        $regularGeneratorType = $this->equipment($project, $emptyElectricGenerator, Equipment::OWNERSHIP_NWC, 'Regular Empty Electric Generator');
        $excluded = $this->equipment($project, $excavator, Equipment::OWNERSHIP_NWC, 'Excluded Generator Group Unit', true);

        $this->dailyStat($project, $nwcExcavator, 8.0, 10.0, 80.0);
        $this->dailyStat($project, $icareDumpTruck, 4.0, 100.0, 40.0);
        $this->dailyStat($project, $regularGeneratorType, 2.0, 20.0, 20.0);
        $this->dailyStat($project, $excluded, 100.0, 500.0, 100.0);

        GeofenceEvent::create([
            'equipment_id' => $nwcExcavator->id,
            'project_id' => $project->id,
            'exit_at' => '2026-07-01 08:00:00',
            'outside_minutes' => 60,
        ]);
        GeofenceEvent::create([
            'equipment_id' => $excluded->id,
            'project_id' => $project->id,
            'exit_at' => '2026-07-01 09:00:00',
            'outside_minutes' => 999,
        ]);

        $filters = [
            'project_id' => $project->id,
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ];
        $dashboard = app(DashboardService::class);
        $overview = $dashboard->getOverview($filters);

        $this->assertSame(3, $overview['equipment_count']);
        $this->assertSame(14.0, $overview['total_hours']);
        $this->assertSame(130.0, $overview['total_distance']);
        $this->assertSame(46.7, $overview['utilization']);
        $this->assertSame([
            ['label' => Equipment::OWNERSHIP_NWC, 'count' => 2],
            ['label' => Equipment::OWNERSHIP_ICARE, 'count' => 1],
        ], $overview['ownership_share']);

        $types = $dashboard->getEquipmentTypeDistributionByOwnership($filters);
        $this->assertSame([
            ['name' => 'Empty Electric Generator', 'total' => 1],
            ['name' => 'Excavator', 'total' => 1],
        ], $types[Equipment::OWNERSHIP_NWC]);
        $this->assertSame([
            ['name' => 'Dump Truck', 'total' => 1],
        ], $types[Equipment::OWNERSHIP_ICARE]);

        $categories = $dashboard->getActualWorkHourCategories($filters);
        $this->assertSame(0, $categories[Equipment::OWNERSHIP_NWC]['overtime']);
        $this->assertSame(1, $categories[Equipment::OWNERSHIP_NWC]['between_7_and_10_hours']);
        $this->assertSame(0, $categories[Equipment::OWNERSHIP_NWC]['less_than_7_hours']);
        $this->assertSame(1, $categories[Equipment::OWNERSHIP_ICARE]['less_than_7_hours']);

        $averages = $dashboard->getAverageMetricsByOwnership($filters);
        $this->assertSame(1, $averages[Equipment::OWNERSHIP_NWC]['engine_hours_equipment_count']);
        $this->assertSame(8.0, $averages[Equipment::OWNERSHIP_NWC]['avg_hours']);
        $this->assertSame(1, $averages[Equipment::OWNERSHIP_ICARE]['mileage_equipment_count']);
        $this->assertSame(100.0, $averages[Equipment::OWNERSHIP_ICARE]['avg_mileage']);

        $leastWorking = $dashboard->getLeastWorking($filters, 10);
        $mostWorking = $dashboard->getMostWorking($filters, 10);
        $this->assertNotContains('Excluded Generator Group Unit', array_column($leastWorking, 'name'));
        $this->assertNotContains('Excluded Generator Group Unit', array_column($mostWorking, 'name'));

        $geofenceRows = $dashboard->getGeofenceOutsideRows($filters, null);
        $this->assertSame(['NWC Excavator'], array_column($geofenceRows, 'grouping'));

        $export = $dashboard->getDashboardExport($filters, 'overview');
        $exportedNames = array_column($export['sections'][1]['rows'], 5);
        $this->assertContains('Regular Empty Electric Generator', $exportedNames);
        $this->assertNotContains('Excluded Generator Group Unit', $exportedNames);
    }

    private function equipment(
        Project $project,
        EquipmentType $type,
        string $ownershipType,
        string $name,
        bool $excluded = false
    ): Equipment {
        return Equipment::create([
            'name' => $name,
            'wialon_unit_id' => uniqid('unit-', true),
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownershipType,
            'matched_wialon_group_id' => $ownershipType === Equipment::OWNERSHIP_ICARE ? '601701922' : '601701903',
            'excluded_from_dashboard' => $excluded,
            'dashboard_exclusion_reason' => $excluded ? Equipment::DASHBOARD_EXCLUSION_GENERATOR_GROUP : null,
        ]);
    }

    private function dailyStat(Project $project, Equipment $equipment, float $hours, float $distance, float $utilization): void
    {
        EquipmentDailyStat::create([
            'stat_date' => '2026-07-01',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => $equipment->ownership_type,
            'worked_hours' => $hours,
            'daytime_hours' => $hours,
            'overtime_hours' => 0,
            'total_hours' => $hours,
            'day_status' => match (true) {
                $hours < 1 => 'less_than_1_hour',
                $hours < 7 => 'less_than_7_hours',
                $hours <= 10 => 'between_7_and_10_hours',
                default => 'over_10_hours',
            },
            'has_overtime' => false,
            'data_available' => true,
            'daytime_data_available' => true,
            'overtime_data_available' => true,
            'distance_km' => $distance,
            'utilization_percent' => $utilization,
        ]);
    }
}
