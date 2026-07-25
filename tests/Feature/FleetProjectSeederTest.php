<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use Database\Seeders\FleetProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetProjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_projects_are_derived_from_configured_wialon_groups(): void
    {
        $this->seed(FleetProjectSeeder::class);

        $this->assertDatabaseHas('projects', [
            'name' => 'Ağdam Azərsu',
            'active' => true,
        ]);
        $this->assertSame(30, Project::query()->where('active', true)->count());
        $this->assertDatabaseHas('project_wialon_groups', [
            'wialon_group_id' => '601701957',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->assertDatabaseHas('project_wialon_groups', [
            'wialon_group_id' => '601701958',
            'ownership_type' => Equipment::OWNERSHIP_ICARE,
        ]);
        $this->assertDatabaseHas('project_wialon_groups', [
            'wialon_group_id' => '601701991',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->assertDatabaseHas('project_wialon_groups', [
            'wialon_group_id' => '601702052',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $this->assertDatabaseMissing('project_wialon_groups', ['wialon_group_id' => '601708440']);
        $this->assertDatabaseMissing('project_wialon_groups', ['wialon_group_id' => '601705305']);
        $this->assertDatabaseMissing('project_wialon_groups', ['wialon_group_id' => '601701871']);
        $this->assertDatabaseMissing('project_wialon_groups', ['wialon_group_id' => '601701870']);
        $this->assertDatabaseMissing('project_wialon_groups', ['wialon_group_id' => '601708543']);
    }

    public function test_old_projects_and_groups_are_removed_from_active_project_source(): void
    {
        $project = Project::create(['name' => 'Old project', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '999999',
            'name' => 'Old project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        $this->seed(FleetProjectSeeder::class);

        $this->assertDatabaseHas('projects', [
            'name' => 'Old project',
            'active' => false,
        ]);
        $this->assertDatabaseMissing('project_wialon_groups', [
            'wialon_group_id' => '999999',
        ]);
    }
}
