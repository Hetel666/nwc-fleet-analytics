<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\WialonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WialonUnitSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_units_are_assigned_from_wialon_group_mapping(): void
    {
        $project = Project::create(['name' => 'Füzuli Ağdam yol', 'active' => true]);

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Füzuli Ağdam yol - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return [
                    [
                        'id' => 25392569,
                        'nm' => 'Unit A',
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'excavator'],
                        ],
                    ],
                ];
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [
                    ['id' => 601701903, 'nm' => 'Füzuli Ağdam yol - NWC', 'u' => [25392569]],
                ];
            }
        });

        $this->artisan('fleet:sync-units')
            ->expectsOutput('Synced 1 Wialon units.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('equipments', [
            'name' => 'Unit A',
            'wialon_unit_id' => '25392569',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->assertDatabaseHas('equipment_types', [
            'name' => 'Excavator',
        ]);
    }

    public function test_project_groups_have_priority_over_global_ownership_groups(): void
    {
        config([
            'fleet.wialon.nwc_group_id' => '601701870',
            'fleet.wialon.icare_group_id' => '601701871',
        ]);

        $project = Project::create(['name' => 'Global ownership project', 'active' => true]);

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return [
                    [
                        'id' => 25392569,
                        'nm' => 'Unit A',
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'excavator'],
                        ],
                    ],
                    [
                        'id' => 25392570,
                        'nm' => 'Unit B',
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'loader'],
                        ],
                    ],
                ];
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [
                    ['id' => 601701903, 'nm' => 'Project - NWC', 'u' => [25392569, 25392570]],
                    ['id' => 601701871, 'nm' => '+İcarə+', 'u' => [25392569]],
                    ['id' => 601701870, 'nm' => '+NWC+', 'u' => [25392570]],
                ];
            }
        });

        $this->artisan('fleet:sync-units')
            ->expectsOutput('Synced 2 Wialon units.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('equipments', [
            'wialon_unit_id' => '25392569',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
        ]);
        $this->assertDatabaseHas('equipments', [
            'wialon_unit_id' => '25392570',
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
        ]);
    }

    public function test_unit_only_in_without_project_group_keeps_ownership_without_project(): void
    {
        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return [
                    [
                        'id' => 40,
                        'nm' => 'Layihesiz unit',
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'loader'],
                        ],
                    ],
                ];
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [
                    ['id' => 601708440, 'nm' => '-Layihəsiz- İcarə', 'u' => [40]],
                ];
            }
        });

        $this->artisan('fleet:sync-units')
            ->expectsOutput('Synced 1 Wialon units.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('equipments', [
            'wialon_unit_id' => '40',
            'project_id' => null,
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
            'matched_wialon_group_id' => '601708440',
            'matched_wialon_group_name' => '-Layihəsiz- İcarə',
        ]);
    }

    public function test_units_from_generator_named_groups_are_excluded_from_dashboard(): void
    {
        $project = Project::create(['name' => 'Generator exclusion project', 'active' => true]);

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '100',
            'name' => 'Project NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return collect([1, 2, 3, 4, 5])
                    ->map(fn (int $id): array => [
                        'id' => $id,
                        'nm' => 'Unit '.$id,
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'excavator'],
                        ],
                    ])
                    ->all();
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [
                    ['id' => 100, 'nm' => 'Project NWC', 'u' => [1, 2, 3, 4, 5]],
                    ['id' => 101, 'nm' => 'Generator', 'u' => [1]],
                    ['id' => 102, 'nm' => '-Generator-', 'u' => [2]],
                    ['id' => 103, 'nm' => 'NWC GENERATOR', 'u' => [3]],
                    ['id' => 104, 'nm' => 'generator equipment', 'u' => [4]],
                ];
            }
        });

        $this->artisan('fleet:sync-units')
            ->expectsOutput('Synced 5 Wialon units.')
            ->assertExitCode(0);

        foreach ([1, 2, 3, 4] as $unitId) {
            $this->assertDatabaseHas('equipments', [
                'wialon_unit_id' => (string) $unitId,
                'excluded_from_dashboard' => true,
                'dashboard_exclusion_reason' => Equipment::DASHBOARD_EXCLUSION_GENERATOR_GROUP,
            ]);
        }

        $this->assertDatabaseHas('equipments', [
            'wialon_unit_id' => '5',
            'excluded_from_dashboard' => false,
            'dashboard_exclusion_reason' => null,
        ]);
    }

    public function test_empty_electric_generator_type_without_generator_group_is_not_excluded(): void
    {
        $project = Project::create(['name' => 'Normal project', 'active' => true]);

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '200',
            'name' => 'Normal NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return [
                    [
                        'id' => 20,
                        'nm' => 'Empty Electric Generator Unit',
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'Empty Electric Generator'],
                        ],
                    ],
                ];
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [
                    ['id' => 200, 'nm' => 'Normal NWC', 'u' => [20]],
                ];
            }
        });

        $this->artisan('fleet:sync-units')
            ->expectsOutput('Synced 1 Wialon units.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('equipments', [
            'wialon_unit_id' => '20',
            'excluded_from_dashboard' => false,
            'dashboard_exclusion_reason' => null,
        ]);
        $this->assertDatabaseHas('equipment_types', [
            'name' => 'Empty Electric Generator',
        ]);
    }

    public function test_sync_units_ignores_project_groups_not_configured_as_real_projects(): void
    {
        $project = Project::create(['name' => 'Old project', 'active' => true]);

        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '999999',
            'name' => 'Old project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return [
                    [
                        'id' => 30,
                        'nm' => 'Unit from old group',
                        'pflds' => [
                            ['n' => 'vehicle_class', 'v' => 'excavator'],
                        ],
                    ],
                ];
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [
                    ['id' => 999999, 'nm' => 'Old project - NWC', 'u' => [30]],
                ];
            }
        });

        $this->artisan('fleet:sync-units')
            ->expectsOutput('Synced 1 Wialon units.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('equipments', [
            'wialon_unit_id' => '30',
            'project_id' => null,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
    }
}
