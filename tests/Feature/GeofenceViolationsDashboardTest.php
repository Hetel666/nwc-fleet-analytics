<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\GeofenceViolationReportRow;
use App\Models\Project;
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
            ->assertSee('Geozonadan çıxma halları')
            ->assertSee('Cari layihə geozonası: Yoxdur')
            ->assertDontSee('Boundary 2h59m')
            ->assertDontSee('Boundary exactly 3h')
            ->assertDontSee('Separate periods 2h A')
            ->assertDontSee('Separate periods 2h B')
            ->assertDontSee('Disallowed type')
            ->assertDontSee('Wrong report source')
            ->assertDontSee('Invalid report timestamps')
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
            ->assertSee('data-geofence-violations-list-link', false)
            ->assertSee(route('geofence-violations.index', [
                'date_from' => '2026-07-27',
                'date_to' => '2026-07-27',
            ]))
            ->assertSee('Geozonadan çıxma halları')
            ->assertSee('Mənbə: Geofence Pozuntuları api')
            ->assertViewHas('geofenceViolationDashboardWidget', function (array $widget) use ($project): bool {
                return data_get($widget, 'kpis.total_violations') === 1
                    && $widget['distribution']->count() === 1
                    && $widget['distribution']->first()['project_id'] === $project->id
                    && $widget['distribution']->first()['count'] === 1;
            });
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
            'report_generated_at' => '2026-07-27 13:05:00',
            ...$overrides,
        ]);
    }
}
