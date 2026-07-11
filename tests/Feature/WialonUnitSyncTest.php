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
}
