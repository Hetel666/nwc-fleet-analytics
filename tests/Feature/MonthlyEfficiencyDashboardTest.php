<?php

namespace Tests\Feature;

use App\Models\DashboardExport;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
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
        $this->assertSame(2, $counts[MonthlyEfficiencyStatus::NORMAL]);

        $cardSummary = app(MonthlyEfficiencyDashboardService::class)->summaryForOwnership([
            'date_from' => '2026-05-15',
            'date_to' => '2026-05-15',
        ], Equipment::OWNERSHIP_NWC);
        $this->assertSame(8, $cardSummary['total']);
        $this->assertSame(2, $cardSummary[MonthlyEfficiencyStatus::CRITICAL_LOW]);
        $this->assertSame(4, $cardSummary[MonthlyEfficiencyStatus::LOW]);
        $this->assertSame(2, $cardSummary[MonthlyEfficiencyStatus::NORMAL]);
        $this->assertSame(25.0, $cardSummary['efficiency_percent']);

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

    private function seedDailyFact(Project $project, string $unitId, string $unitName, string $vehicleType, string $ownership, string $date, float $hours): void
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
            'source_report_name' => 'Qrup report Engine hours (api)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
