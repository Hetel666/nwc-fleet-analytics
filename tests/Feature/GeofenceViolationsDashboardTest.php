<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\GeofenceViolationReportRow;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceViolationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('geofence-violations.index'))
            ->assertRedirect(route('login'));

        $this->getJson(route('dashboard.geofence-violations.drilldown'))
            ->assertUnauthorized();
    }

    public function test_only_valid_report_periods_strictly_over_three_hours_are_displayed(): void
    {
        $user = User::factory()->create(['active' => true]);
        [$project, $excavatorType] = $this->fleet();
        $passengerType = EquipmentType::create(['name' => 'Passenger Car']);

        $this->reportRow($project, $excavatorType, 'Boundary 2h59m', 10_740);
        $this->reportRow($project, $excavatorType, 'Boundary exactly 3h', 10_800);
        $this->reportRow($project, $excavatorType, 'Boundary 3h00m01s', 10_801);
        $this->reportRow($project, $excavatorType, 'Boundary 3h01m', 10_860);
        $this->reportRow($project, $excavatorType, 'Completed 4h', 14_400, [
            'is_active' => false,
            'ended_at' => '2026-07-27 14:00:00',
        ]);
        $this->reportRow($project, $excavatorType, 'Separate periods 2h A', 7_200, [
            'wialon_unit_id' => 'separate-period-unit',
            'exited_at' => '2026-07-26 06:00:00',
            'last_confirmed_at' => '2026-07-26 08:00:00',
        ]);
        $this->reportRow($project, $excavatorType, 'Separate periods 2h B', 7_200, [
            'wialon_unit_id' => 'separate-period-unit',
            'exited_at' => '2026-07-26 10:00:00',
            'last_confirmed_at' => '2026-07-26 12:00:00',
        ]);
        $this->reportRow($project, $passengerType, 'Disallowed type', 18_000);
        $this->reportRow($project, $excavatorType, 'Wrong report source', 18_000, [
            'report_name' => 'Another report',
        ]);
        $this->reportRow($project, $excavatorType, 'Invalid report timestamps', 18_000, [
            'exited_at' => '2026-07-27 15:00:00',
            'last_confirmed_at' => '2026-07-27 14:00:00',
        ]);
        $this->reportRow($project, $excavatorType, 'Legacy row without report range', 18_000, [
            'report_period_from' => null,
            'report_period_to' => null,
        ]);

        Equipment::create([
            'name' => 'Inside another project geofence',
            'wialon_unit_id' => 'inside-other-project',
            'equipment_type_id' => $excavatorType->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-26',
            'date_to' => '2026-07-27',
        ]));

        $response->assertOk()
            ->assertSee('Geofence Pozuntuları')
            ->assertSee('Boundary 3h00m01s')
            ->assertSee('Boundary 3h01m')
            ->assertSee('Completed 4h')
            ->assertSee('Aktiv pozuntu')
            ->assertSee('Tamamlanmış pozuntu')
            ->assertSee('Geofence Pozuntuları')
            ->assertSee('Hesabat sonuna aktiv')
            ->assertSee('Cari layihə geozonası: Yoxdur')
            ->assertDontSee('Boundary 2h59m')
            ->assertDontSee('Boundary exactly 3h')
            ->assertDontSee('Separate periods 2h A')
            ->assertDontSee('Separate periods 2h B')
            ->assertDontSee('Disallowed type')
            ->assertDontSee('Wrong report source')
            ->assertDontSee('Invalid report timestamps')
            ->assertDontSee('Legacy row without report range')
            ->assertDontSee('Inside another project geofence')
            ->assertViewHas('kpis', [
                'total_violations' => 3,
                'active_violations' => 2,
                'active_projects' => 1,
                'longest_duration_seconds' => 14_400,
            ])
            ->assertViewHas('distribution', function ($distribution) use ($project): bool {
                return $distribution->count() === 1
                    && $distribution->first()['project_id'] === $project->id
                    && $distribution->first()['count'] === 3
                    && $distribution->first()['percentage'] === 100.0;
            });
    }

    public function test_filters_are_independent_from_existing_dashboard_filters(): void
    {
        $user = User::factory()->create(['active' => true]);
        [$project, $type] = $this->fleet();
        $secondProject = Project::create(['name' => 'Second project', 'active' => true]);

        $this->reportRow($project, $type, 'NWC active', 11_000, [
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);
        $this->reportRow($secondProject, $type, 'ICARE completed', 12_000, [
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'is_active' => false,
            'ended_at' => '2026-07-27 14:00:00',
        ]);

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-27',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'status' => 'active',
            'search' => 'NWC',
        ]))
            ->assertOk()
            ->assertSee('NWC active')
            ->assertDontSee('ICARE completed')
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['total_violations'] === 1);

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-27',
        ]))
            ->assertOk()
            ->assertViewHas('distribution', fn ($distribution): bool => $distribution->count() === 2
                && $distribution->sum('count') === 2
                && $distribution->sum('percentage') === 100.0);
    }

    public function test_partial_or_reversed_date_range_is_rejected(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-27',
        ]))->assertSessionHasErrors('date_to');

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-28',
            'date_to' => '2026-07-27',
        ]))->assertSessionHasErrors('date_to');
    }

    public function test_excessive_dashboard_period_is_rejected(): void
    {
        config()->set('geofence_violations.max_dashboard_period_days', 31);
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-07-28',
        ]))->assertSessionHasErrors('date_to');
    }

    public function test_report_donut_is_added_to_geozones_tab_without_replacing_existing_widget(): void
    {
        $user = User::factory()->create(['active' => true]);
        [$project, $type] = $this->fleet();

        $this->reportRow($project, $type, 'Report violation', 14_400);
        $this->reportRow($project, $type, 'Below threshold', 10_800);

        $this->actingAs($user)->get(route('dashboard', [
            'tab' => 'geozones',
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-27',
        ]))
            ->assertOk()
            ->assertSee('data-widget-key="geofence-analysis"', false)
            ->assertSee('data-widget-key="geofence-violations-report"', false)
            ->assertDontSee('data-widget-key="current-live"', false)
            ->assertDontSee('Cari vəziyyət')
            ->assertSeeInOrder([
                'col-12 col-xl-6 dashboard-widget geofence-paired-widget',
                'data-widget-key="geofence-analysis"',
                'col-12 col-xl-6 dashboard-widget geofence-paired-widget',
                'data-widget-key="geofence-violations-report"',
            ], false)
            ->assertDontSee('foreign-geofence-kpi-grid', false)
            ->assertDontSee('foreign-geofence-kpi-label', false)
            ->assertSee('data-geofence-violations-list-link', false)
            ->assertSee('data-geofence-violations-drilldown', false)
            ->assertDontSee('Ətraflı baxış')
            ->assertSee(route('dashboard.geofence-violations.drilldown'))
            ->assertSee('Geofence Transferləri')
            ->assertSee('Mənbə: Geofence Pozuntuları api')
            ->assertViewHas('geofenceViolationDashboardWidget', function (array $widget) use ($project): bool {
                return data_get($widget, 'kpis.total_violations') === 1
                    && $widget['distribution']->count() === 1
                    && $widget['distribution']->first()['project_id'] === $project->id
                    && $widget['distribution']->first()['count'] === 1;
            });
    }

    public function test_report_donut_drilldown_uses_independent_rows_and_marks_current_project_unknown(): void
    {
        $user = User::factory()->create(['active' => true]);
        [$project, $type] = $this->fleet();
        $otherProject = Project::create(['name' => 'Other project', 'active' => true]);

        $this->reportRow($project, $type, 'Outside unit', 14_400, [
            'last_project_geofence' => null,
            'last_location' => null,
        ]);
        $this->reportRow($otherProject, $type, 'Other outside unit', 18_000);
        $this->reportRow($project, $type, 'Below threshold', 10_800);

        $this->actingAs($user)
            ->getJson(route('dashboard.geofence-violations.drilldown', [
                'date_from' => '2026-07-27',
                'date_to' => '2026-07-27',
                'project_id' => $project->id,
                'ownership' => 'nwc',
                'per_page' => 20,
            ]))
            ->assertOk()
            ->assertJsonPath('title', $project->name.' - Geofence Pozuntuları')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('columns.current_project', 'Cari layihə')
            ->assertJsonPath('data.0.equipment_name', 'Outside unit')
            ->assertJsonPath('data.0.home_project', $project->name)
            ->assertJsonPath('data.0.current_project', 'Layihədən kənarda / Məlumatsız')
            ->assertJsonPath('data.0.last_project_geofence', 'Məlumatsız')
            ->assertJsonPath('data.0.last_location', 'Məlumatsız')
            ->assertJsonMissing(['equipment_name' => 'Other outside unit'])
            ->assertJsonMissing(['equipment_name' => 'Below threshold']);
    }

    public function test_repair_project_is_shown_in_operational_violation_dashboard(): void
    {
        $user = User::factory()->create(['active' => true]);
        [$project, $type] = $this->fleet();
        $repair = Project::create(['name' => 'Təmir', 'active' => true]);
        $this->reportRow($project, $type, 'Operational violation', 14_400);
        $this->reportRow($repair, $type, 'Repair violation', 14_400);

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-27',
        ]))
            ->assertOk()
            ->assertSee('Operational violation')
            ->assertSee('Repair violation')
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['total_violations'] === 2);
    }

    public function test_layihesiz_rows_are_hidden_from_geofence_violations_dashboard_and_kept_for_audit(): void
    {
        $user = User::factory()->create(['active' => true]);
        [$allowedProject, $type] = $this->fleet();
        $excludedProject = Project::create(['name' => 'Layihəsiz', 'active' => true]);
        $excludedGroup = ProjectWialonGroup::create([
            'project_id' => $excludedProject->id,
            'wialon_group_id' => '601705305',
            'name' => 'Layihəsiz - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'is_active' => true,
        ]);

        $this->reportRow($allowedProject, $type, 'Visible violation', 14_400);
        $this->reportRow($excludedProject, $type, 'Hidden Layihəsiz violation', 14_400, [
            'project_wialon_group_id' => $excludedGroup->id,
        ]);

        $this->actingAs($user)->get(route('geofence-violations.index', [
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-27',
        ]))
            ->assertOk()
            ->assertSee('Visible violation')
            ->assertDontSee('Hidden Layihəsiz violation')
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['total_violations'] === 1);

        $this->artisan('geofence:purge-excluded-groups', ['--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('geofence_violation_report_rows', [
            'equipment_name' => 'Hidden Layihəsiz violation',
        ]);
    }

    /**
     * @return array{Project, EquipmentType}
     */
    private function fleet(): array
    {
        return [
            Project::create(['name' => 'Füzuli Ağdam yol', 'active' => true]),
            EquipmentType::create(['name' => 'Excavator']),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reportRow(
        Project $project,
        EquipmentType $type,
        string $name,
        int $durationSeconds,
        array $overrides = []
    ): GeofenceViolationReportRow {
        static $sequence = 0;
        $sequence++;

        $equipment = Equipment::firstOrCreate(
            ['wialon_unit_id' => $overrides['wialon_unit_id'] ?? 'unit-'.$sequence],
            [
                'name' => $name,
                'equipment_type_id' => $type->id,
                'project_id' => $project->id,
                'ownership_type' => $overrides['ownership_type'] ?? Equipment::OWNERSHIP_NWC,
                'active' => true,
            ]
        );

        return GeofenceViolationReportRow::create([
            'report_name' => GeofenceViolationReportRow::REPORT_NAME,
            'period_key' => sha1($name.'|'.$sequence),
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'wialon_unit_id' => $equipment->wialon_unit_id,
            'equipment_name' => $name,
            'equipment_type' => $type->name,
            'ownership_type' => $equipment->ownership_type,
            'project_name' => $project->name,
            'last_project_geofence' => 'Previous project geofence',
            'exited_at' => '2026-07-27 10:00:00',
            'last_confirmed_at' => '2026-07-27 13:00:01',
            'outside_duration_seconds' => $durationSeconds,
            'last_location' => '40.4093, 49.8671',
            'is_active' => true,
            'report_period_from' => '2026-07-27 00:00:00',
            'report_period_to' => '2026-07-27 23:59:59',
            'report_generated_at' => '2026-07-27 13:05:00',
            ...$overrides,
        ]);
    }
}
