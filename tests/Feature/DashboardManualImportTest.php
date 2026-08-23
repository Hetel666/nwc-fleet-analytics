<?php

namespace Tests\Feature;

use App\Models\DashboardDataImport;
use App\Models\EfficiencyDailyFact;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\XlsxFixture;
use Tests\TestCase;

class DashboardManualImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_manual_import_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard-data-imports.index'))
            ->assertOk()
            ->assertSee('Wialon XLSX yüklə')
            ->assertSee('Report for Aylıq effektivlik')
            ->assertSee('Qrup date report Engine hours (api)');

        $this->actingAs($viewer)->get(route('admin.dashboard-data-imports.index'))->assertForbidden();
    }

    public function test_daily_xlsx_preview_does_not_write_and_confirmation_replaces_exact_scope(): void
    {
        [$admin, $project, $equipment] = $this->scenario();
        $otherProject = Project::query()->create(['name' => 'Other project', 'active' => true]);
        $otherGroup = ProjectWialonGroup::query()->create([
            'project_id' => $otherProject->id,
            'wialon_group_id' => 'group-other',
            'name' => 'Other project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $otherEquipment = Equipment::query()->create([
            'name' => '77-BB-002',
            'registration_number' => '77-BB-002',
            'wialon_unit_id' => 'unit-2',
            'equipment_type_id' => $equipment->equipment_type_id,
            'project_id' => $otherProject->id,
            'project_wialon_group_id' => $otherGroup->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'planned_daily_hours' => 10,
            'active' => true,
            'excluded_from_dashboard' => false,
        ]);
        $sameProjectEquipment = Equipment::query()->create([
            'name' => '77-CC-003',
            'registration_number' => '77-CC-003',
            'wialon_unit_id' => 'unit-3',
            'equipment_type_id' => $equipment->equipment_type_id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $equipment->project_wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'planned_daily_hours' => 10,
            'active' => true,
            'excluded_from_dashboard' => false,
        ]);

        $this->oldDailyFact($project, $equipment, 4);
        $this->oldDailyFact($otherProject, $otherEquipment, 6);
        $this->oldDailyFact($project, $sameProjectEquipment, 5);
        $file = XlsxFixture::upload([
            'Engine hours' => [
                ['Grouping', 'Engine hours', 'Mileage (adjusted)', 'Beginning', 'End', 'Unit ID'],
                ['77-AA-001', null, null, null, null, 'unit-1'],
                ['2026-08-01', 8.5, '42.40 km', '07:00:00', '18:00:00', 'unit-1'],
            ],
        ], 'daily.xlsx');

        $response = $this->actingAs($admin)->post(route('admin.dashboard-data-imports.store'), [
            'module' => DashboardDataImport::MODULE_DAILY_EFFICIENCY,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'files' => [$file],
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $import = DashboardDataImport::query()->firstOrFail();
        $response->assertRedirect(route('admin.dashboard-data-imports.show', $import));
        $this->assertSame(DashboardDataImport::STATUS_READY, $import->status);
        $this->assertSame(1, $import->accepted_rows);
        $this->assertSame(4.0, (float) EfficiencyDailyFact::query()->where('wialon_unit_id', 'unit-1')->value('engine_hours_decimal'));

        $this->actingAs($admin)
            ->post(route('admin.dashboard-data-imports.confirm', $import))
            ->assertRedirect(route('admin.dashboard-data-imports.show', $import));

        $this->assertSame(DashboardDataImport::STATUS_COMPLETED, $import->refresh()->status);
        $this->assertSame(8.5, (float) EfficiencyDailyFact::query()->where('wialon_unit_id', 'unit-1')->value('engine_hours_decimal'));
        $this->assertSame(8.5, (float) EquipmentDailyStat::query()->where('equipment_id', $equipment->id)->value('worked_hours'));
        $this->assertSame(85.0, (float) EquipmentDailyStat::query()->where('equipment_id', $equipment->id)->value('utilization_percent'));
        $this->assertSame(42.4, (float) DB::table('daily_unit_aggregates')->where('equipment_id', $equipment->id)->value('mileage'));
        $this->assertSame(6.0, (float) EfficiencyDailyFact::query()->where('wialon_unit_id', 'unit-2')->value('engine_hours_decimal'));
        $this->assertSame(5.0, (float) EfficiencyDailyFact::query()->where('wialon_unit_id', 'unit-3')->value('engine_hours_decimal'));
    }

    public function test_rows_outside_selected_period_block_confirmation(): void
    {
        [$admin, $project, $equipment] = $this->scenario();
        $this->oldDailyFact($project, $equipment, 4);
        $file = XlsxFixture::upload([
            'Engine hours' => [
                ['Grouping', 'Engine hours', 'Mileage', 'Beginning', 'End', 'Unit ID'],
                ['77-AA-001', null, null, null, null, 'unit-1'],
                ['2026-08-02', 8.5, 10, '07:00:00', '18:00:00', 'unit-1'],
            ],
        ]);

        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.store'), [
            'module' => DashboardDataImport::MODULE_DAILY_EFFICIENCY,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'project_id' => $project->id,
            'files' => [$file],
        ])->assertSessionHasNoErrors();

        $import = DashboardDataImport::query()->firstOrFail();
        $this->assertSame(DashboardDataImport::STATUS_INVALID, $import->status);
        $this->assertGreaterThan(0, $import->rejected_rows);

        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.confirm', $import));
        $this->assertSame(4.0, (float) EfficiencyDailyFact::query()->where('wialon_unit_id', 'unit-1')->value('engine_hours_decimal'));
    }

    public function test_monthly_xlsx_writes_total_geofence_and_calculated_unknown_rows(): void
    {
        [$admin, $project] = $this->scenario();
        $file = XlsxFixture::upload([
            'Engine hours' => [
                ['Grouping', 'Engine hours', 'Mileage (adjusted)', 'Beginning', 'End', 'Unit ID'],
                ['77-AA-001', null, null, null, null, 'unit-1'],
                ['2026-08-01', 10, 30, '00:00:01', '23:59:58', 'unit-1'],
            ],
            'Geofence' => [
                ['Grouping', 'Name', 'Entry time', 'Exit time', 'Engine hours', 'Mileage', 'Visits', 'Unit ID'],
                ['77-AA-001', null, null, null, null, null, null, 'unit-1'],
                ['2026-08-01', 'LOT3', '08:00:00', '17:00:00', 7.5, 20, 2, 'unit-1'],
            ],
        ], 'monthly.xlsx');

        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.store'), [
            'module' => DashboardDataImport::MODULE_MONTHLY_EFFICIENCY,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'project_id' => $project->id,
            'files' => [$file],
        ])->assertSessionHasNoErrors();

        $import = DashboardDataImport::query()->firstOrFail();
        $this->assertSame(DashboardDataImport::STATUS_READY, $import->status);
        $this->assertSame(2, $import->accepted_rows);
        $this->assertDatabaseCount('monthly_efficiency_unit_geofence_facts', 0);

        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.confirm', $import));

        $this->assertDatabaseCount('monthly_efficiency_unit_geofence_facts', 3);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'stat_date' => '2026-08-01',
            'wialon_unit_id' => 'unit-1',
            'segment_type' => 'total',
            'geofence_name' => 'Total',
            'engine_hours_decimal' => 10,
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'segment_type' => 'geofence',
            'geofence_name' => 'LOT3',
            'engine_hours_decimal' => 7.5,
        ]);
        $this->assertDatabaseHas('monthly_efficiency_unit_geofence_facts', [
            'segment_type' => 'unknown',
            'geofence_name' => 'Naməlum',
            'engine_hours_decimal' => 2.5,
        ]);
    }

    public function test_broken_xlsx_is_staged_as_invalid_without_changing_dashboard_data(): void
    {
        [$admin, $project, $equipment] = $this->scenario();
        $this->oldDailyFact($project, $equipment, 4);
        $file = UploadedFile::fake()->createWithContent('broken.xlsx', 'not-an-xlsx-archive');

        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.store'), [
            'module' => DashboardDataImport::MODULE_DAILY_EFFICIENCY,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'project_id' => $project->id,
            'files' => [$file],
        ])->assertSessionHasNoErrors();

        $import = DashboardDataImport::query()->firstOrFail();
        $this->assertSame(DashboardDataImport::STATUS_INVALID, $import->status);
        $this->assertNotEmpty($import->error_message);
        $this->assertSame(4.0, (float) EfficiencyDailyFact::query()->where('wialon_unit_id', 'unit-1')->value('engine_hours_decimal'));
    }

    public function test_completed_file_cannot_be_applied_twice_to_the_same_scope(): void
    {
        [$admin, $project] = $this->scenario();
        $fixture = XlsxFixture::upload([
            'Engine hours' => [
                ['Grouping', 'Engine hours', 'Mileage', 'Beginning', 'End', 'Unit ID'],
                ['77-AA-001', null, null, null, null, 'unit-1'],
                ['2026-08-01', 8.5, 10, '07:00:00', '18:00:00', 'unit-1'],
            ],
        ]);
        $contents = file_get_contents($fixture->getRealPath());
        $this->assertIsString($contents);

        $payload = [
            'module' => DashboardDataImport::MODULE_DAILY_EFFICIENCY,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'project_id' => $project->id,
        ];

        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.store'), [
            ...$payload,
            'files' => [$fixture],
        ])->assertSessionHasNoErrors();
        $import = DashboardDataImport::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.dashboard-data-imports.confirm', $import));

        $second = UploadedFile::fake()->createWithContent('same-report.xlsx', $contents);
        $this->actingAs($admin)->from(route('admin.dashboard-data-imports.index'))->post(route('admin.dashboard-data-imports.store'), [
            ...$payload,
            'files' => [$second],
        ])->assertRedirect(route('admin.dashboard-data-imports.index'))
            ->assertSessionHasErrors('files');

        $this->assertDatabaseCount('dashboard_data_imports', 1);
    }

    /** @return array{User,Project,Equipment} */
    private function scenario(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::query()->create(['name' => 'Yuxarı Şirvan LOT3', 'active' => true]);
        $type = EquipmentType::query()->create(['name' => 'Dump Truck']);
        $group = ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => 'group-lot3',
            'name' => 'Yuxarı Şirvan LOT3 - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $equipment = Equipment::query()->create([
            'name' => '77-AA-001',
            'registration_number' => '77-AA-001',
            'wialon_unit_id' => 'unit-1',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'project_wialon_group_id' => $group->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'planned_daily_hours' => 10,
            'active' => true,
            'excluded_from_dashboard' => false,
        ]);

        return [$admin, $project, $equipment];
    }

    private function oldDailyFact(Project $project, Equipment $equipment, float $hours): void
    {
        EfficiencyDailyFact::query()->create([
            'business_date' => '2026-08-01',
            'project_id' => $project->id,
            'wialon_group_id' => (string) $equipment->projectWialonGroup->wialon_group_id,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => $equipment->name,
            'vehicle_type' => 'Dump Truck',
            'ownership' => Equipment::OWNERSHIP_NWC,
            'engine_hours_decimal' => $hours,
            'engine_seconds' => (int) round($hours * 3600),
            'efficiency_status' => '1_7',
            'source_report_template_id' => 1,
            'source_report_name' => config('fleet.wialon.efficiency_report_template_name'),
        ]);
    }
}
