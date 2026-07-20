<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Project;
use App\Services\WialonGroupClassificationService;
use App\Services\WialonService;
use Database\Seeders\FleetProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WialonProjectClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_project_directory_contains_thirty_projects(): void
    {
        $this->seed(FleetProjectSeeder::class);

        $this->assertSame(30, Project::query()->where('active', true)->count());
        $this->assertDatabaseMissing('projects', ['name' => '-Layihəsiz- NWC', 'active' => true]);
        $this->assertDatabaseMissing('projects', ['name' => '+NWC+', 'active' => true]);
    }

    public function test_project_and_without_project_groups_are_classified(): void
    {
        $this->seed(FleetProjectSeeder::class);

        $service = app(WialonGroupClassificationService::class);

        $projectClassification = $service->classifyUnit([
            ['id' => '601701957', 'name' => 'Ağdam Azərsu - NWC'],
            ['id' => '601701870', 'name' => '+NWC+'],
        ], 'unit-1');

        $this->assertSame(Equipment::OWNERSHIP_NWC, $projectClassification['ownership']);
        $this->assertSame('601701957', $projectClassification['matched_group_id']);
        $this->assertFalse($projectClassification['without_project']);

        $withoutProjectClassification = $service->classifyUnit([
            ['id' => '601705305', 'name' => '-Layihəsiz- NWC'],
        ], 'unit-2');

        $this->assertNull($withoutProjectClassification['project_id']);
        $this->assertSame(Equipment::OWNERSHIP_NWC, $withoutProjectClassification['ownership']);
        $this->assertTrue($withoutProjectClassification['without_project']);
    }

    public function test_conflicting_project_groups_are_logged_and_not_randomly_selected(): void
    {
        $this->seed(FleetProjectSeeder::class);
        Log::spy();

        $classification = app(WialonGroupClassificationService::class)->classifyUnit([
            ['id' => '601701957', 'name' => 'Ağdam Azərsu - NWC'],
            ['id' => '601701903', 'name' => 'Füzuli Ağdam yol - NWC'],
        ], 'unit-conflict');

        $this->assertTrue($classification['conflict']);
        $this->assertNull($classification['project_id']);
        Log::shouldHaveReceived('warning')->with('Unit belongs to multiple project groups', \Mockery::type('array'));
    }

    public function test_replace_projects_command_is_idempotent_and_creates_backup(): void
    {
        Project::create(['name' => 'Old project', 'active' => true]);
        $this->app->instance(WialonService::class, new class extends WialonService
        {
            public function __construct()
            {
            }

            public function getUnits(bool $full = false): array
            {
                return [];
            }

            public function getUnitGroups(array $groupIds = []): array
            {
                return [];
            }
        });

        $this->artisan('fleet:replace-projects', ['--force' => true])
            ->assertExitCode(0);

        $this->artisan('fleet:replace-projects', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(30, Project::query()->where('active', true)->count());
        $this->assertSame(30, Project::query()->where('active', true)->distinct('name')->count('name'));
        $this->assertNotEmpty(glob(storage_path('app/backups/projects-before-replacement-*.json')));
    }
}
