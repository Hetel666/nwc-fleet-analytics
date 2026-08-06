<?php

namespace Tests\Feature;

use App\Models\DashboardExport;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\MonthlyEfficiencyDashboardService;
use App\Support\MonthlyEfficiencyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
            'date_from' => '2026-05-15',
            'date_to' => '2026-05-15',
            'ownership' => 'nwc',
        ]))->assertOk()->json('data');

        $counts = collect($summary)->pluck('count', 'status');
        $this->assertSame(2, $counts[MonthlyEfficiencyStatus::CRITICAL_LOW]);
        $this->assertSame(4, $counts[MonthlyEfficiencyStatus::LOW]);
        $this->assertSame(3, $counts[MonthlyEfficiencyStatus::NORMAL]);

        $cardSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-05-15',
            'date_to' => '2026-05-15',
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
