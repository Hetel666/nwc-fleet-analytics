<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DashboardWialonIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_modal_and_excel_routes_do_not_call_wialon(): void
    {
        config(['fleet.wialon.live_dashboard_reports' => true]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct() {}

            public function getReportTablesRows(
                int|string $resourceId,
                int|string $templateId,
                int|string $objectId,
                int $from,
                int $to,
                int $chunkSize = 500,
                int $intervalFlags = 0,
                bool $remoteExec = false,
                ?int $requestTimeout = null
            ): array {
                throw new RuntimeException('Dashboard read path must not call Wialon.');
            }
        });

        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::create(['name' => 'LOT3', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Excavator']);
        $group = ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701935',
            'name' => 'LOT3 NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $equipment = Equipment::create([
            'name' => 'Unit 01',
            'registration_number' => '90-AA-001',
            'wialon_unit_id' => '1001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => (string) $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        DB::table('equipment_daily_stats')->insert([
            'stat_date' => '2026-07-01',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 8,
            'distance_km' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-01',
                'project_id' => $project->id,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('dashboard.drilldown.units', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-01',
                'project_id' => $project->id,
                'ownership' => 'nwc',
                'work_category' => 'from_7_to_10',
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('dashboard.export', [
                'block' => 'overview',
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-01',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
