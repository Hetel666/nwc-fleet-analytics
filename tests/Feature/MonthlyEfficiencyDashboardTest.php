<?php

namespace Tests\Feature;

use App\Models\DashboardExport;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Models\WialonReportTemplate;
use App\Services\MonthlyEfficiencyDashboardService;
use App\Services\WialonService;
use App\Support\MonthlyEfficiencyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MonthlyEfficiencyDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fleet.wialon.monthly_efficiency_source', 'group_report');
    }

    public function test_monthly_efficiency_uses_allowed_types_distinct_units_and_boundary_statuses(): void
    {
        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Füzuli', 'active' => true]);
        $unassignedProject = Project::query()->create(['name' => 'Layihəsiz', 'active' => true]);
        $repairProject = Project::query()->create(['name' => 'Təmir', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $this->seedMonthlyUnit($project, $dumpTruck, 'u-14999', 149.99, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-15000', 150.00, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-15001', 150.01, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-15100', 151.00, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-19900', 199.00, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-19999', 199.99, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-20000', 200.00, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $dumpTruck, 'u-20001', 200.01, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($project, $loader, 'loader-1', 240.00, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($unassignedProject, $dumpTruck, 'excluded-unassigned', 500.00, Equipment::OWNERSHIP_NWC);
        $this->seedMonthlyUnit($repairProject, $dumpTruck, 'excluded-repair', 500.00, Equipment::OWNERSHIP_NWC);
        $this->seedDailyFact($project, 'split-unit', 'Split Unit', 'Dump Truck', Equipment::OWNERSHIP_ICARE, '2026-05-01', 60.00);
        $this->seedDailyFact($project, 'split-unit', 'Split Unit', 'Dump Truck', Equipment::OWNERSHIP_ICARE, '2026-05-02', 160.00);

        $summary = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.summary', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-01',
            'ownership' => 'nwc',
        ]))->assertOk()->json('data');

        $counts = collect($summary)->pluck('count', 'status');
        $this->assertSame(2, $counts[MonthlyEfficiencyStatus::CRITICAL_LOW]);
        $this->assertSame(4, $counts[MonthlyEfficiencyStatus::LOW]);
        $this->assertSame(3, $counts[MonthlyEfficiencyStatus::NORMAL]);

        $cardSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-01',
        ], Equipment::OWNERSHIP_NWC);
        $this->assertSame(9, $cardSummary['total']);
        $this->assertSame(2, $cardSummary[MonthlyEfficiencyStatus::CRITICAL_LOW]);
        $this->assertSame(4, $cardSummary[MonthlyEfficiencyStatus::LOW]);
        $this->assertSame(3, $cardSummary[MonthlyEfficiencyStatus::NORMAL]);
        $this->assertSame(33.33, $cardSummary['efficiency_percent']);

        $nwcProjects = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.projects', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'ownership' => 'nwc',
        ]))->assertOk()->json('data');

        $this->assertNotContains('Layihəsiz', collect($nwcProjects)->pluck('project')->all());
        $this->assertNotContains('Təmir', collect($nwcProjects)->pluck('project')->all());

        $rentalProjects = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.projects', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'ownership' => 'icare',
            'status' => 'normal',
        ]))->assertOk()->json();

        $this->assertSame(1, $rentalProjects['summary']['total']);
        $this->assertSame(1, $rentalProjects['data'][0]['count']);
        $this->assertSame('Füzuli', $rentalProjects['data'][0]['project']);

        $units = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.units', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'ownership' => 'icare',
            'project_id' => $project->id,
            'status' => 'normal',
        ]))->assertOk()->json('data');

        $this->assertCount(1, $units);
        $this->assertSame('220.00', $units[0]['current_hours']);
        $this->assertSame('200', $units[0]['normative_hours']);
        $this->assertSame('110.00%', $units[0]['efficiency_percent']);
    }

    public function test_monthly_efficiency_rejects_ranges_that_span_multiple_months(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.projects', [
            'date_from' => '2026-05-31',
            'date_to' => '2026-06-01',
            'ownership' => 'nwc',
        ]))
            ->assertStatus(422)
            ->assertJson(['message' => 'Aylıq effektivlik üçün bir təqvim ayı seçilməlidir.']);
    }

    public function test_monthly_efficiency_splits_one_unit_by_actual_daily_project(): void
    {
        $user = User::factory()->create(['active' => true]);
        $groupProject = Project::query()->create(['name' => 'Current group project', 'active' => true]);
        $firstProject = Project::query()->create(['name' => 'First work project', 'active' => true]);
        $secondProject = Project::query()->create(['name' => 'Second work project', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);

        Equipment::query()->create([
            'name' => '110-FD-084',
            'registration_number' => '110-FD-084',
            'wialon_unit_id' => 'split-084',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $groupProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        $this->seedDailyFact($groupProject, 'split-084', '110-FD-084', 'Dump Truck', Equipment::OWNERSHIP_NWC, '2026-07-01', 60.00, [
            'c' => ['110-FD-084', '60.00', 'First work project: yard', 'First work project: yard'],
        ]);
        $this->seedDailyFact($groupProject, 'split-084', '110-FD-084', 'Dump Truck', Equipment::OWNERSHIP_NWC, '2026-07-02', 160.00, [
            'c' => ['110-FD-084', '160.00', 'Second work project: road', 'Second work project: road'],
        ]);

        $cardSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $cardSummary['total']);
        $this->assertSame(1, $cardSummary[MonthlyEfficiencyStatus::NORMAL]);
        $this->assertSame(100.0, $cardSummary['efficiency_percent']);

        $projects = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.projects', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'ownership' => 'nwc',
        ]))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['First work project', 'Second work project'],
            collect($projects)->pluck('project')->all(),
        );
        $this->assertSame([1, 1], collect($projects)->pluck('count')->sort()->values()->all());

        $secondProjectUnits = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.units', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'ownership' => 'nwc',
            'project_id' => $secondProject->id,
        ]))->assertOk()->json('data');

        $this->assertCount(1, $secondProjectUnits);
        $this->assertSame('110-FD-084', $secondProjectUnits[0]['registration_number']);
        $this->assertSame('Second work project', $secondProjectUnits[0]['project']);
        $this->assertSame('2026-07-02 - 2026-07-02', $secondProjectUnits[0]['period']);
        $this->assertSame(1, $secondProjectUnits[0]['synced_days_count']);
        $this->assertSame('160.00', $secondProjectUnits[0]['current_hours']);
        $this->assertSame('Wialon lokasiya', $secondProjectUnits[0]['project_source_label']);
    }

    public function test_monthly_efficiency_reads_only_the_configured_source_report(): void
    {
        $project = Project::query()->create(['name' => 'Füzuli', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);

        Equipment::query()->create([
            'name' => 'SOURCE-UNIT',
            'registration_number' => 'SOURCE-UNIT',
            'wialon_unit_id' => 'source-unit',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        $this->seedDailyFact(
            $project,
            'source-unit',
            'SOURCE-UNIT',
            'Dump Truck',
            Equipment::OWNERSHIP_NWC,
            '2026-07-01',
            210.00,
            null,
            'Qrup report Engine hours (api)',
        );
        $this->seedDailyFact(
            $project,
            'source-unit',
            'SOURCE-UNIT',
            'Dump Truck',
            Equipment::OWNERSHIP_NWC,
            '2026-07-01',
            90.00,
            null,
            'Qrup date report Engine hours (api)',
        );

        config()->set('fleet.wialon.monthly_efficiency_source', 'group_report');
        $groupReportSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $groupReportSummary['total']);
        $this->assertSame(1, $groupReportSummary[MonthlyEfficiencyStatus::NORMAL]);
        $this->assertSame(100.0, $groupReportSummary['efficiency_percent']);

        config()->set('fleet.wialon.monthly_efficiency_source', 'date_report');
        $dateReportSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $dateReportSummary['total']);
        $this->assertSame(1, $dateReportSummary[MonthlyEfficiencyStatus::CRITICAL_LOW]);
        $this->assertSame(0.0, $dateReportSummary['efficiency_percent']);
    }

    public function test_monthly_efficiency_can_use_dashboard_daily_stats_source(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_source', 'daily_stats');

        $user = User::factory()->create(['active' => true]);
        $currentGroupProject = Project::query()->create(['name' => 'Current group project', 'active' => true]);
        $firstProject = Project::query()->create(['name' => 'Xocavənd təlim mərkəzi', 'active' => true]);
        $secondProject = Project::query()->create(['name' => 'Füzuli Xocavənd avtomobil yolu', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);

        $equipment = Equipment::query()->create([
            'name' => '110-FD-084',
            'registration_number' => '110-FD-084',
            'wialon_unit_id' => '110-FD-084',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $currentGroupProject->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        DB::table('equipment_daily_stats')->insert([
            [
                'stat_date' => '2026-07-01',
                'equipment_id' => $equipment->id,
                'project_id' => $firstProject->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'worked_hours' => 80.00,
                'distance_km' => 0,
                'utilization_percent' => 0,
                'calculation_source' => 'wialon_engine_hours_report',
                'calculation_status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'stat_date' => '2026-07-02',
                'equipment_id' => $equipment->id,
                'project_id' => $secondProject->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'worked_hours' => 140.00,
                'distance_km' => 0,
                'utilization_percent' => 0,
                'calculation_source' => 'wialon_engine_hours_report',
                'calculation_status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $cardSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(1, $cardSummary['total']);
        $this->assertSame(1, $cardSummary[MonthlyEfficiencyStatus::NORMAL]);
        $this->assertSame(100.0, $cardSummary['efficiency_percent']);

        $projects = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.projects', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'ownership' => 'nwc',
        ]))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['Xocavənd təlim mərkəzi', 'Füzuli Xocavənd avtomobil yolu'],
            collect($projects)->pluck('project')->all(),
        );

        $units = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.units', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'ownership' => 'nwc',
            'project_id' => $secondProject->id,
        ]))->assertOk()->json('data');

        $this->assertCount(1, $units);
        $this->assertSame('110-FD-084', $units[0]['registration_number']);
        $this->assertSame('Füzuli Xocavənd avtomobil yolu', $units[0]['project']);
        $this->assertSame('2026-07-02 - 2026-07-02', $units[0]['period']);
        $this->assertSame(1, $units[0]['synced_days_count']);
        $this->assertSame('140.00', $units[0]['current_hours']);
        $this->assertSame('24 saat Dashboard cədvəli', $units[0]['project_source_label']);
    }

    public function test_monthly_efficiency_export_uses_only_selected_daily_stats_period(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_source', 'daily_stats');

        $project = Project::query()->create(['name' => 'Fuzuli', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $equipment = Equipment::query()->create([
            'name' => '77-JG-365',
            'registration_number' => '77-JG-365',
            'wialon_unit_id' => '77-JG-365',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        foreach ([
            '2026-08-01' => 12.00,
            '2026-08-06' => 18.00,
            '2026-08-10' => 40.00,
            '2026-08-11' => 50.00,
        ] as $date => $hours) {
            DB::table('equipment_daily_stats')->insert([
                'stat_date' => $date,
                'equipment_id' => $equipment->id,
                'project_id' => $project->id,
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'worked_hours' => $hours,
                'distance_km' => 0,
                'utilization_percent' => 0,
                'calculation_source' => 'wialon_engine_hours_report',
                'calculation_status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $export = app(MonthlyEfficiencyDashboardService::class)->export([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-06',
            'ownership' => 'nwc',
        ]);

        $this->assertSame('ayliq-effektivlik-NWC-2026-08-01-2026-08-06.xlsx', $export['filename']);
        $this->assertSame(['Dövr', '2026-08-01 - 2026-08-06'], $export['filters'][0]);

        $detailRows = collect($export['sections'][2]['rows']);
        $this->assertCount(1, $detailRows);
        $this->assertSame('2026-08-01 - 2026-08-06', $detailRows[0][4]);
        $this->assertSame(2, $detailRows[0][5]);
        $this->assertSame('30.00', $detailRows[0][6]);
        $this->assertStringNotContainsString('2026-08-10', json_encode($export, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2026-08-11', json_encode($export, JSON_THROW_ON_ERROR));

        $savedExport = app(MonthlyEfficiencyDashboardService::class)->export([
            'month' => '2026-08',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'object_from' => '2026-08-01',
            'object_to' => '2026-08-06',
            'ownership_type' => 'nwc',
        ]);

        $this->assertSame('ayliq-effektivlik-NWC-2026-08-01-2026-08-06.xlsx', $savedExport['filename']);
        $this->assertSame(['Dövr', '2026-08-01 - 2026-08-06'], $savedExport['filters'][0]);
        $this->assertSame('2026-08-01 - 2026-08-06', $savedExport['sections'][2]['rows'][0][4]);
        $this->assertSame('30.00', $savedExport['sections'][2]['rows'][0][6]);
        $this->assertStringNotContainsString('2026-08-10', json_encode($savedExport, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2026-08-11', json_encode($savedExport, JSON_THROW_ON_ERROR));
    }

    public function test_monthly_efficiency_uses_object_group_ownership_for_daily_stats_source(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_source', 'daily_stats');

        $project = Project::query()->create(['name' => 'Fuzuli', 'active' => true]);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => 'icare-group',
            'name' => 'Fuzuli ICARE',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'is_active' => true,
        ]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $equipment = Equipment::query()->create([
            'name' => 'GROUP-OWNED-1',
            'registration_number' => 'GROUP-OWNED-1',
            'wialon_unit_id' => 'group-owned-1',
            'equipment_type_id' => $dumpTruck->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        DB::table('equipment_daily_stats')->insert([
            'stat_date' => '2026-07-01',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 210.00,
            'distance_km' => 0,
            'utilization_percent' => 0,
            'calculation_source' => 'wialon_engine_hours_report',
            'calculation_status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nwc = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], Equipment::OWNERSHIP_NWC);
        $icare = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], Equipment::OWNERSHIP_ICARE);

        $this->assertSame(0, $nwc['total']);
        $this->assertSame(1, $icare['total']);
        $this->assertSame(1, $icare[MonthlyEfficiencyStatus::NORMAL]);
    }

    public function test_monthly_efficiency_object_dashboard_uses_only_requested_types_and_geofence_drilldown(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Report for Aylıq effektivlik');

        $user = User::factory()->create(['active' => true]);
        $project = Project::query()->create(['name' => 'Object source', 'active' => true]);
        $unassignedProject = Project::query()->create(['name' => 'Layihəsiz', 'active' => true]);
        $repairProject = Project::query()->create(['name' => 'Təmir', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $excavator = EquipmentType::query()->create(['name' => 'Excavator']);
        $bulldozer = EquipmentType::query()->create(['name' => 'Bulldozer']);
        $loader = EquipmentType::query()->create(['name' => 'Loader']);

        $dump = $this->seedEquipment($project, $dumpTruck, '10-AF-065', Equipment::OWNERSHIP_NWC);
        $excavatorUnit = $this->seedEquipment($project, $excavator, '10-EX-100', Equipment::OWNERSHIP_NWC);
        $bulldozerUnit = $this->seedEquipment($project, $bulldozer, '10-BD-100', Equipment::OWNERSHIP_NWC);
        $loaderUnit = $this->seedEquipment($project, $loader, '10-LD-100', Equipment::OWNERSHIP_NWC);
        $unassignedUnit = $this->seedEquipment($unassignedProject, $loader, '10-LY-100', Equipment::OWNERSHIP_NWC);
        $repairUnit = $this->seedEquipment($repairProject, $dumpTruck, '10-RP-100', Equipment::OWNERSHIP_NWC);

        DB::table('wialon_geofences')->insert([
            'wialon_geofence_id' => 'zone-object-source',
            'name' => 'Füzuli Xocavənd yolu',
            'resource_id' => '601701680',
            'linked_project_id' => $project->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedObjectFact($dump, '2026-07-01', 'total', 'Total', 8.0, 20.0);
        $this->seedObjectFact($dump, '2026-07-01', 'geofence', 'Füzuli Xocavənd yolu', 7.0, 18.0, 1);
        $this->seedObjectFact($dump, '2026-07-01', 'unknown', 'Naməlum', 1.0, 2.0);
        $this->seedObjectFact($excavatorUnit, '2026-07-01', 'total', 'Total', 12.0, 30.0);
        $this->seedObjectFact($bulldozerUnit, '2026-07-01', 'total', 'Total', 16.0, 40.0);
        $this->seedObjectFact($loaderUnit, '2026-07-01', 'total', 'Total', 24.0, 50.0);
        $this->seedObjectFact($unassignedUnit, '2026-07-01', 'total', 'Total', 24.0, 50.0);
        $this->seedObjectFact($repairUnit, '2026-07-01', 'total', 'Total', 24.0, 50.0);

        $summary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ], Equipment::OWNERSHIP_NWC);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary[MonthlyEfficiencyStatus::CRITICAL_LOW]);
        $this->assertSame(1, $summary[MonthlyEfficiencyStatus::LOW]);
        $this->assertSame(2, $summary[MonthlyEfficiencyStatus::NORMAL]);

        $export = app(MonthlyEfficiencyDashboardService::class)->export([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'ownership' => 'nwc',
        ]);
        $summaryRows = collect($export['sections'][0]['rows'])->keyBy(1);
        $projectRows = collect($export['sections'][1]['rows'])->keyBy(0);

        $this->assertSame(1, $summaryRows[MonthlyEfficiencyStatus::labels()[MonthlyEfficiencyStatus::CRITICAL_LOW]][2]);
        $this->assertSame(1, $summaryRows[MonthlyEfficiencyStatus::labels()[MonthlyEfficiencyStatus::LOW]][2]);
        $this->assertSame(2, $summaryRows[MonthlyEfficiencyStatus::labels()[MonthlyEfficiencyStatus::NORMAL]][2]);
        $this->assertSame('14', $export['filters'][4][1]);
        $this->assertSame(1, $projectRows['Object source'][2]);
        $this->assertSame(1, $projectRows['Object source'][3]);
        $this->assertSame(2, $projectRows['Object source'][4]);
        $this->assertSame(4, $projectRows['Object source'][5]);

        $objects = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.objects', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'ownership' => 'nwc',
            'status' => 'critical_low',
        ]))->assertOk()->json('data');

        $this->assertCount(1, $objects);
        $this->assertSame('10-AF-065', $objects[0]['registration_number']);
        $this->assertSame('Dump Truck', $objects[0]['vehicle_type']);
        $this->assertSame('8.00', $objects[0]['total_hours']);
        $this->assertSame('7.00', $objects[0]['known_hours']);
        $this->assertSame('1.00', $objects[0]['unknown_hours']);
        $this->assertSame('Object source', $objects[0]['actual_project']);

        $normalObjects = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.objects', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'ownership' => 'nwc',
            'status' => 'normal',
        ]))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['Bulldozer', 'Loader'],
            collect($normalObjects)->pluck('vehicle_type')->all(),
        );
        $this->assertNotContains('10-LY-100', collect($normalObjects)->pluck('registration_number')->all());
        $this->assertNotContains('10-RP-100', collect($normalObjects)->pluck('registration_number')->all());

        $geofences = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.object-geofences', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'ownership' => 'nwc',
            'wialon_unit_id' => '10-AF-065',
        ]))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['Füzuli Xocavənd yolu', 'Naməlum'],
            collect($geofences)->pluck('geofence_name')->all(),
        );
        $this->assertSame(
            'Object source',
            collect($geofences)->firstWhere('geofence_name', 'Füzuli Xocavənd yolu')['actual_project'],
        );
        $this->assertSame(
            'Object source',
            collect($geofences)->firstWhere('geofence_name', 'Naməlum')['actual_project'],
        );

        $days = $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.object-geofence-days', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'ownership' => 'nwc',
            'wialon_unit_id' => '10-AF-065',
            'geofence_name' => 'Füzuli Xocavənd yolu',
        ]))->assertOk()->json('data');

        $this->assertCount(1, $days);
        $this->assertSame('2026-07-01', $days[0]['date']);
        $this->assertSame('Object source', $days[0]['actual_project']);
        $this->assertSame('7.00', $days[0]['motosaat']);
    }

    public function test_monthly_efficiency_export_is_queued_with_monthly_block(): void
    {
        Queue::fake();
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->getJson(route('api.dashboard.monthly-efficiency.export', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'ownership' => 'nwc',
        ]))->assertAccepted();

        $this->assertDatabaseHas('dashboard_exports', [
            'user_id' => $user->id,
            'block' => 'monthly_efficiency',
            'status' => DashboardExport::STATUS_PENDING,
        ]);
    }

    public function test_object_sync_uses_exact_monthly_group_report(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Report for Aylıq effektivlik');
        $project = Project::query()->create(['name' => 'Group report project', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $equipment = $this->seedEquipment($project, $dumpTruck, '6001001', Equipment::OWNERSHIP_NWC);
        ProjectWialonGroup::query()->whereKey($equipment->project_wialon_group_id)->update([
            'wialon_group_id' => '601701957',
        ]);
        WialonReportTemplate::query()->create([
            'wialon_template_id' => 24,
            'name' => 'Report for Aylıq effektivlik',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit_group',
            'is_active' => true,
        ]);

        $start = CarbonImmutable::parse('2026-07-01 08:00:00', 'Asia/Baku')->timestamp;
        $end = CarbonImmutable::parse('2026-07-01 16:00:00', 'Asia/Baku')->timestamp;
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('loginByToken')->once()->with(false)->andReturn('group-sid');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('group-sid');
        $wialon->shouldReceive('logoutSession')->once()->with('group-sid');
        $wialon->shouldReceive('executeReport')
            ->once()
            ->withArgs(fn ($resource, $template, $group): bool => $resource === 601701680
                && $template === 24
                && (string) $group === '601701957')
            ->andReturn(['reportResult' => ['tables' => [
                [
                    'name' => 'unit_group_engine_hours',
                    'label' => 'Engine hours',
                    'header' => ['Grouping', '', '', 'Engine hours', '', '', '', 'Mileage', 'Beginning', 'End'],
                    'header_type' => ['', '', '', 'duration', '', '', '', 'correct_mileage', 'time_begin', 'time_end'],
                    'rows' => 1,
                    'level' => 2,
                ],
                [
                    'name' => 'unit_group_zones_visit',
                    'label' => 'Geofence',
                    'header' => ['Grouping', 'Name', 'Entry time', 'Exit time', 'Engine hours', 'Mileage', 'Visits'],
                    'header_type' => ['', 'zone_name', 'time_begin', 'time_end', 'duration_in', 'mileage', 'visits_count'],
                    'rows' => 1,
                    'level' => 4,
                ],
            ]]]);
        $wialon->shouldReceive('selectReportResultRows')->once()->withArgs(fn ($table): bool => $table === 0)->andReturn([[
            'uid' => 6001001,
            'c' => ['6001001'],
            'r' => [[
                'c' => ['2026-07-01', '', '', '8:00:00', '', '', '', '10 km', ['v' => $start], ['v' => $end]],
            ]],
        ]]);
        $wialon->shouldReceive('selectReportResultRows')->once()->withArgs(fn ($table): bool => $table === 1)->andReturn([[
            'uid' => 6001001,
            'c' => ['6001001'],
            'r' => [[
                'c' => ['2026-07-01'],
                'r' => [[
                    'c' => ['Project zone', '', ['v' => $start], ['v' => $end], '7:00:00', '8 km', '1'],
                ]],
            ]],
        ]]);
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-01',
            '--project' => $project->id,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '6001001',
            'segment_type' => 'total',
            'engine_hours_decimal' => 8,
            'source_report_template_id' => 24,
            'source_report_name' => 'Report for Aylıq effektivlik',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '6001001',
            'segment_type' => 'geofence',
            'geofence_name' => 'Project zone',
            'engine_hours_decimal' => 7,
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '6001001',
            'segment_type' => 'unknown',
            'engine_hours_decimal' => 1,
        ]);
    }

    public function test_object_sync_prefers_unit_report_when_unit_and_group_templates_exist(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Report for AylД±q effektivlik');
        $project = Project::query()->create(['name' => 'Unit report project', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $this->seedEquipment($project, $dumpTruck, '6001002', Equipment::OWNERSHIP_NWC);

        WialonReportTemplate::query()->create([
            'wialon_template_id' => 24,
            'name' => 'Report for AylД±q effektivlik',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit_group',
            'is_active' => true,
        ]);
        WialonReportTemplate::query()->create([
            'wialon_template_id' => 25,
            'name' => 'Report for AylД±q effektivlik (unit)',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit',
            'is_active' => true,
        ]);

        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'Asia/Baku')->timestamp;
        $end = CarbonImmutable::parse('2026-07-01 23:59:58', 'Asia/Baku')->timestamp;
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getSessionId')->once()->andReturn('unit-sid');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('unit-sid');
        $wialon->shouldReceive('executeReport')
            ->once()
            ->withArgs(fn ($resource, $template, $unit): bool => $resource === 601701680
                && $template === 25
                && (string) $unit === '6001002')
            ->andReturn(['reportResult' => ['tables' => [
                [
                    'name' => 'Engine hours',
                    'header' => ['Grouping', 'Engine hours', 'Mileage', 'Beginning', 'End'],
                    'header_type' => ['grouping', 'duration', 'mileage', 'time_begin', 'time_end'],
                    'rows' => 1,
                ],
                [
                    'name' => 'Geofence Engine hours',
                    'header' => ['Grouping', 'Geofence', 'Entry time', 'Exit time', 'Engine hours', 'Mileage', 'Visits'],
                    'header_type' => ['grouping', 'zone_name', 'time_begin', 'time_end', 'duration', 'mileage', 'visits_count'],
                    'rows' => 1,
                ],
            ]]]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(0, 0, 0, 'unit-sid')
            ->andReturn([['c' => [
                '2026-07-01',
                '22:48:36',
                '308.06 km',
                ['v' => $start],
                ['v' => $end],
            ]]]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(1, 0, 0, 'unit-sid')
            ->andReturn([['c' => ['2026-07-01']]]);
        $wialon->shouldReceive('getReportResultSubrows')
            ->once()
            ->with(1, 0, 'unit-sid')
            ->andReturn([['c' => [
                '',
                'LOT-3 Гњmumi',
                ['v' => CarbonImmutable::parse('2026-07-01 04:30:29', 'Asia/Baku')->timestamp],
                ['v' => $end],
                '19:29:24',
                '240.30 km',
                '1',
            ]]]);
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-01',
            '--unit' => '6001002',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '6001002',
            'segment_type' => 'total',
            'engine_hours_decimal' => 22.81,
            'mileage_km' => 308.06,
            'source_report_template_id' => 25,
            'source_report_name' => 'Report for AylД±q effektivlik (unit)',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '6001002',
            'segment_type' => 'geofence',
            'engine_hours_decimal' => 19.49,
            'mileage_km' => 240.30,
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '6001002',
            'segment_type' => 'unknown',
            'engine_hours_decimal' => 3.32,
            'mileage_km' => 67.76,
        ]);
    }

    public function test_object_sync_merges_wialon_utc_split_rows_into_local_day(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Unit Monthly Report');

        $project = Project::query()->create(['name' => 'Timezone project', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $this->seedEquipment($project, $dumpTruck, '600709593', Equipment::OWNERSHIP_NWC);

        WialonReportTemplate::query()->create([
            'wialon_template_id' => 25,
            'name' => 'Unit Monthly Report',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit',
            'is_active' => true,
        ]);

        $firstStart = CarbonImmutable::parse('2026-08-01 00:00:00', 'Asia/Baku')->timestamp;
        $firstEnd = CarbonImmutable::parse('2026-08-01 04:30:24', 'Asia/Baku')->timestamp;
        $secondStart = CarbonImmutable::parse('2026-08-01 04:30:29', 'Asia/Baku')->timestamp;
        $secondEnd = CarbonImmutable::parse('2026-08-01 23:59:58', 'Asia/Baku')->timestamp;

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getSessionId')->once()->andReturn('unit-sid');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('unit-sid');
        $wialon->shouldReceive('executeReport')
            ->once()
            ->withArgs(fn ($resource, $template, $unit): bool => $resource === 601701680
                && $template === 25
                && (string) $unit === '600709593')
            ->andReturn(['reportResult' => ['tables' => [
                [
                    'name' => 'unit_engine_hours',
                    'label' => 'Engine hours',
                    'header' => ['Grouping', 'Custom column', 'Custom column', 'Engine hours', 'Equipment Type', 'Vendor', 'Year', 'Mileage (adjusted)', 'Beginning', 'End'],
                    'header_type' => ['', 'user_column', 'user_column', 'duration', 'user_column', 'user_column', 'user_column', 'correct_mileage', 'time_begin', 'time_end'],
                    'rows' => 2,
                ],
                [
                    'name' => 'unit_zones_visit',
                    'label' => 'Geofence',
                    'header' => ['Grouping', 'Name', 'Entry time', 'Exit time', 'Engine hours', 'Mileage', 'Visits'],
                    'header_type' => ['', 'zone_name', 'time_begin', 'time_end', 'duration_in', 'mileage', 'visits_count'],
                    'rows' => 2,
                ],
            ]]]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(0, 0, 1, 'unit-sid')
            ->andReturn([
                [
                    't1' => $firstStart,
                    't2' => $firstEnd,
                    'c' => ['2026-07-31', '-----', '-----', '3.32', '-----', '-----', '-----', '67.77 km', ['v' => $firstStart], ['v' => $firstEnd]],
                ],
                [
                    't1' => $secondStart,
                    't2' => $secondEnd,
                    'c' => ['2026-08-01', '-----', '-----', '19.49', '-----', '-----', '-----', '240.30 km', ['v' => $secondStart], ['v' => $secondEnd]],
                ],
            ]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(1, 0, 1, 'unit-sid')
            ->andReturn([
                ['t1' => $firstStart, 't2' => $firstEnd, 'c' => ['2026-07-31']],
                ['t1' => $secondStart, 't2' => $secondEnd, 'c' => ['2026-08-01']],
            ]);
        $wialon->shouldReceive('getReportResultSubrows')
            ->once()
            ->with(1, 0, 'unit-sid')
            ->andReturn([[
                't1' => $firstStart,
                't2' => $firstEnd,
                'c' => ['LOT-3 Umumi', '', ['v' => $firstStart], ['v' => $firstEnd], '3.32', '67.77 km', '2'],
            ]]);
        $wialon->shouldReceive('getReportResultSubrows')
            ->once()
            ->with(1, 1, 'unit-sid')
            ->andReturn([[
                't1' => $secondStart,
                't2' => $secondEnd,
                'c' => ['LOT-3 Umumi', '', ['v' => $secondStart], ['v' => $secondEnd], '19.49', '240.30 km', '1'],
            ]]);
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-08-01',
            '--to' => '2026-08-01',
            '--unit' => '600709593',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-31',
            'wialon_unit_id' => '600709593',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-08-01',
            'wialon_unit_id' => '600709593',
            'segment_type' => 'total',
            'engine_hours_decimal' => 22.81,
            'mileage_km' => 308.07,
            'started_at' => '2026-08-01 00:00:00',
            'ended_at' => '2026-08-01 23:59:58',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-08-01',
            'wialon_unit_id' => '600709593',
            'segment_type' => 'geofence',
            'geofence_name' => 'LOT-3 Umumi',
            'engine_hours_decimal' => 22.81,
            'mileage_km' => 308.07,
            'visits_count' => 3,
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-08-01',
            'wialon_unit_id' => '600709593',
            'segment_type' => 'unknown',
            'engine_hours_decimal' => 0.0,
            'mileage_km' => 0.0,
        ]);
    }

    public function test_forced_object_sync_purges_full_unit_period_when_new_report_has_missing_days(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Monthly Unit Report');

        $project = Project::query()->create(['name' => 'Object source', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $equipment = $this->seedEquipment($project, $dumpTruck, '10-AF-065', Equipment::OWNERSHIP_NWC);

        WialonReportTemplate::query()->create([
            'wialon_template_id' => 91,
            'name' => 'Monthly Unit Report',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit',
            'is_active' => true,
        ]);

        $this->seedObjectFact($equipment, '2026-07-01', 'total', 'Total', 99.0, 0);
        DB::table('monthly_efficiency_unit_geofence_facts')
            ->where('wialon_unit_id', '10-AF-065')
            ->update([
                'source_report_template_id' => 91,
                'source_report_name' => 'Monthly Unit Report',
            ]);
        DB::table('monthly_efficiency_unit_geofence_facts')->insert([
            'stat_date' => '2026-07-02',
            'equipment_id' => $equipment->id,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => (string) $equipment->name,
            'registration_number' => $equipment->registration_number,
            'vehicle_type' => $equipment->type->name,
            'ownership_type' => $equipment->ownership_type,
            'segment_type' => 'total',
            'geofence_name' => 'Total',
            'engine_hours_decimal' => 77.0,
            'engine_seconds' => 277200,
            'mileage_km' => 0,
            'visits_count' => 0,
            'source_report_template_id' => 91,
            'source_report_name' => 'Monthly Unit Report (unit)',
            'raw_row_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $run = HistoricalRecalculation::query()->create([
            'uuid' => 'f6085a83-2f25-41e1-8ecb-00ff6ab77254',
            'signature' => 'monthly-command-progress',
            'status' => HistoricalRecalculation::STATUS_RUNNING,
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'scope' => HistoricalRecalculation::SCOPE_ALL_PROJECTS,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'timezone' => 'Asia/Baku',
            'force' => true,
            'project_ids' => [],
        ]);
        $task = HistoricalRecalculationTask::query()->create([
            'historical_recalculation_id' => $run->id,
            'status' => HistoricalRecalculationTask::STATUS_RUNNING,
            'operation' => HistoricalRecalculation::OPERATION_FETCH,
            'stat_date' => '2026-07-01',
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getSessionId')->once()->andReturn('sid-test');
        $wialon->shouldReceive('cleanupReportResult')->twice();
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => [
                'tables' => [
                    [
                        'name' => 'Engine hours',
                        'header' => ['Grouping', 'Engine hours', 'Mileage', 'Beginning', 'End'],
                        'header_type' => ['grouping', 'duration', 'mileage', 'time_begin', 'time_end'],
                        'rows' => 1,
                    ],
                    [
                        'name' => 'Geofence Engine hours',
                        'header' => ['Grouping', 'Geofence', 'Entry time', 'Exit time', 'Engine hours', 'Mileage', 'Visits'],
                        'header_type' => ['grouping', 'zone_name', 'time_begin', 'time_end', 'duration', 'mileage', 'visits_count'],
                        'rows' => 1,
                    ],
                ],
            ],
        ]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(0, 0, 0, 'sid-test')
            ->andReturn([['c' => [
                '2026-07-01',
                '8:00:00',
                '10 km',
                ['t' => '04:00:00', 'v' => CarbonImmutable::parse('2026-07-01 08:00:00', 'Asia/Baku')->timestamp],
                ['t' => '12:00:00', 'v' => CarbonImmutable::parse('2026-07-01 16:00:00', 'Asia/Baku')->timestamp],
            ]]]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(1, 0, 0, 'sid-test')
            ->andReturn([['c' => ['2026-07-01']]]);
        $wialon->shouldReceive('getReportResultSubrows')
            ->once()
            ->with(1, 0, 'sid-test')
            ->andReturn([['c' => [
                '',
                'Object source',
                ['t' => '04:00:00', 'v' => CarbonImmutable::parse('2026-07-01 08:00:00', 'Asia/Baku')->timestamp],
                ['t' => '11:00:00', 'v' => CarbonImmutable::parse('2026-07-01 15:00:00', 'Asia/Baku')->timestamp],
                '7:00:00',
                '8 km',
                '1',
            ]]]);
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-02',
            '--unit' => '10-AF-065',
            '--force' => true,
            '--unit-chunk' => 1,
            '--flush-rows' => 2,
            '--historical-task-id' => $task->id,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-02',
            'wialon_unit_id' => '10-AF-065',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '10-AF-065',
            'segment_type' => 'total',
            'source_report_name' => 'Monthly Unit Report',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '10-AF-065',
            'segment_type' => 'total',
            'started_at' => '2026-07-01 08:00:00',
            'ended_at' => '2026-07-01 16:00:00',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '10-AF-065',
            'segment_type' => 'geofence',
            'started_at' => '2026-07-01 08:00:00',
            'ended_at' => '2026-07-01 15:00:00',
        ]);
        $this->assertSame(3, DB::table('monthly_efficiency_unit_geofence_facts')->where('wialon_unit_id', '10-AF-065')->count());
        $this->assertSame(1, (int) $task->refresh()->equipment_count);
        $this->assertNotNull($task->last_heartbeat_at);
    }

    public function test_forced_object_sync_deletes_selected_scope_before_wialon_reload(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Monthly Unit Report');

        $project = Project::query()->create(['name' => 'Predelete scope', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $target = $this->seedEquipment($project, $dumpTruck, '10-PREDELETE', Equipment::OWNERSHIP_NWC);
        $other = $this->seedEquipment($project, $dumpTruck, '10-KEEP', Equipment::OWNERSHIP_NWC);

        WialonReportTemplate::query()->create([
            'wialon_template_id' => 91,
            'name' => 'Monthly Unit Report',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit',
            'is_active' => true,
        ]);

        $this->seedObjectFact($target, '2026-07-01', 'total', 'Total', 99.0, 0);
        $this->seedObjectFact($other, '2026-07-01', 'total', 'Total', 88.0, 0);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getSessionId')->once()->andReturn('sid-test');
        $wialon->shouldReceive('cleanupReportResult')->twice()->with('sid-test');
        $wialon->shouldReceive('executeReport')
            ->once()
            ->andThrow(new \RuntimeException('Wialon API error 1 for report/exec_report.'));
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-01',
            '--unit' => '10-PREDELETE',
            '--force' => true,
            '--unit-chunk' => 1,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '10-PREDELETE',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-07-01',
            'wialon_unit_id' => '10-KEEP',
            'engine_hours_decimal' => 88.0,
        ]);
    }

    public function test_object_sync_records_unknown_hours_when_wialon_report_has_no_geofence_table(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Monthly Unit Report');

        $project = Project::query()->create(['name' => 'Object source', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $this->seedEquipment($project, $dumpTruck, '10-MISSING-GEOFENCE', Equipment::OWNERSHIP_NWC);

        WialonReportTemplate::query()->create([
            'wialon_template_id' => 91,
            'name' => 'Monthly Unit Report',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit',
            'is_active' => true,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getSessionId')->once()->andReturn('sid-test');
        $wialon->shouldReceive('cleanupReportResult')->twice();
        $wialon->shouldReceive('executeReport')->once()->andReturn([
            'reportResult' => [
                'tables' => [
                    [
                        'name' => 'Engine hours',
                        'header' => ['Grouping', 'Engine hours', 'Mileage', 'Beginning', 'End'],
                        'header_type' => ['grouping', 'duration', 'mileage', 'time_begin', 'time_end'],
                        'rows' => 1,
                    ],
                ],
            ],
        ]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(0, 0, 0, 'sid-test')
            ->andReturn([['c' => ['2026-07-01', '8:00:00', '12 km', '08:00:00', '16:00:00']]]);
        $wialon->shouldReceive('getReportResultSubrows')->never();
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-01',
            '--unit' => '10-MISSING-GEOFENCE',
            '--force' => true,
            '--unit-chunk' => 1,
            '--flush-rows' => 2,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '10-MISSING-GEOFENCE',
            'segment_type' => 'total',
            'engine_hours_decimal' => 8,
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '10-MISSING-GEOFENCE',
            'segment_type' => 'unknown',
            'engine_hours_decimal' => 8,
        ]);
    }

    public function test_object_sync_skips_single_wialon_report_error_and_completes_day_when_other_units_succeed(): void
    {
        config()->set('fleet.wialon.monthly_efficiency_unit_report_template_name', 'Monthly Unit Report');

        $project = Project::query()->create(['name' => 'Object source', 'active' => true]);
        $dumpTruck = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $this->seedEquipment($project, $dumpTruck, '10-WIALON-SKIP', Equipment::OWNERSHIP_NWC);
        $this->seedEquipment($project, $dumpTruck, '10-WIALON-OK', Equipment::OWNERSHIP_NWC);

        WialonReportTemplate::query()->create([
            'wialon_template_id' => 91,
            'name' => 'Monthly Unit Report',
            'resource_id' => 601701680,
            'report_type' => 'avl_unit',
            'is_active' => true,
        ]);

        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getSessionId')->twice()->andReturn('sid-test');
        $wialon->shouldReceive('cleanupReportResult')->times(4);
        $wialon->shouldReceive('executeReport')
            ->once()
            ->ordered()
            ->andThrow(new \RuntimeException('Wialon API error 4 for report/exec_report.'));
        $wialon->shouldReceive('executeReport')
            ->once()
            ->ordered()
            ->andReturn([
                'reportResult' => [
                    'tables' => [
                        [
                            'name' => 'Engine hours',
                            'header' => ['Grouping', 'Engine hours', 'Mileage', 'Beginning', 'End'],
                            'header_type' => ['grouping', 'duration', 'mileage', 'time_begin', 'time_end'],
                            'rows' => 1,
                        ],
                    ],
                ],
            ]);
        $wialon->shouldReceive('getReportResultRows')
            ->once()
            ->with(0, 0, 0, 'sid-test')
            ->andReturn([['c' => ['2026-07-01', '6:30:00', '9 km', '08:00:00', '14:30:00']]]);
        $wialon->shouldReceive('getReportResultSubrows')->never();
        $this->app->instance(WialonService::class, $wialon);

        $this->artisan('monthly-efficiency:sync-objects', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-01',
            '--force' => true,
            '--unit-chunk' => 1,
            '--flush-rows' => 2,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '10-WIALON-SKIP',
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'wialon_unit_id' => '10-WIALON-OK',
            'segment_type' => 'unknown',
            'engine_hours_decimal' => 6.5,
        ]);
    }

    private function seedMonthlyUnit(Project $project, EquipmentType $type, string $unitId, float $hours, string $ownership): void
    {
        Equipment::query()->create([
            'name' => strtoupper($unitId),
            'registration_number' => strtoupper($unitId),
            'wialon_unit_id' => $unitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => $ownership,
            'active' => true,
        ]);

        $this->seedDailyFact($project, $unitId, strtoupper($unitId), $type->name, $ownership, '2026-05-01', $hours);
    }

    private function seedEquipment(Project $project, EquipmentType $type, string $unitId, string $ownership): Equipment
    {
        $projectGroup = ProjectWialonGroup::query()->firstOrCreate(
            [
                'project_id' => $project->id,
                'wialon_group_id' => 'project-group-'.$project->id.'-'.$ownership,
            ],
            [
                'name' => $project->name.' - '.$ownership,
                'ownership_type' => $ownership,
                'is_active' => true,
            ],
        );

        $allowedGroupId = 'allowed-monthly-object-types';
        DB::table('wialon_unit_groups')->updateOrInsert(
            ['wialon_group_id' => $allowedGroupId],
            [
                'name' => 'Bulldozer, Excavator, Loader, Backhoe Loader, Road Grader, Road Roller, Dump Truck',
                'units_count' => 1,
                'is_active' => true,
                'last_seen_at' => now(),
                'last_synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $wialonUnitGroupId = DB::table('wialon_unit_groups')->where('wialon_group_id', $allowedGroupId)->value('id');

        DB::table('wialon_unit_group_members')->updateOrInsert(
            [
                'wialon_group_id' => $allowedGroupId,
                'wialon_unit_item_id' => $unitId,
            ],
            [
                'wialon_unit_group_id' => $wialonUnitGroupId,
                'last_synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return Equipment::query()->create([
            'name' => $unitId,
            'registration_number' => $unitId,
            'wialon_unit_id' => $unitId,
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $projectGroup->id,
            'matched_wialon_group_id' => $projectGroup->wialon_group_id,
            'ownership_type' => $ownership,
            'active' => true,
        ]);
    }

    private function seedObjectFact(Equipment $equipment, string $date, string $segment, string $geofence, float $hours, float $mileage, int $visits = 0): void
    {
        DB::table('monthly_efficiency_unit_geofence_facts')->insert([
            'stat_date' => $date,
            'equipment_id' => $equipment->id,
            'project_id' => $equipment->project_id,
            'project_wialon_group_id' => $equipment->project_wialon_group_id,
            'wialon_group_id' => $equipment->matched_wialon_group_id,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => (string) $equipment->name,
            'registration_number' => $equipment->registration_number,
            'vehicle_type' => $equipment->type->name,
            'ownership_type' => $equipment->ownership_type,
            'segment_type' => $segment,
            'geofence_name' => $geofence,
            'engine_hours_decimal' => $hours,
            'engine_seconds' => (int) round($hours * 3600),
            'mileage_km' => $mileage,
            'visits_count' => $visits,
            'started_at' => $date.' 00:00:00',
            'ended_at' => $date.' 23:59:59',
            'source_report_template_id' => 25,
            'source_report_name' => 'Report for Aylıq effektivlik',
            'raw_row_json' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDailyFact(Project $project, string $unitId, string $unitName, string $vehicleType, string $ownership, string $date, float $hours, ?array $rawRow = null, string $sourceReportName = 'Qrup report Engine hours (api)'): void
    {
        DB::table('efficiency_daily_facts')->insert([
            'business_date' => $date,
            'project_id' => $project->id,
            'wialon_group_id' => 'group-'.$project->id,
            'wialon_unit_id' => $unitId,
            'unit_name' => $unitName,
            'vehicle_type' => $vehicleType,
            'ownership' => $ownership,
            'engine_hours_decimal' => $hours,
            'engine_seconds' => (int) round($hours * 3600),
            'started_at' => $date.' 00:00:00',
            'ended_at' => $date.' 23:59:59',
            'mileage_km' => 0,
            'efficiency_status' => 'over_10',
            'source_report_template_id' => 601701680,
            'source_report_name' => $sourceReportName,
            'raw_row_json' => $rawRow === null ? null : json_encode($rawRow, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
