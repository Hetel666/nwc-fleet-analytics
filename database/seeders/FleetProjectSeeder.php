<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectWialonGeofenceGroup;
use App\Models\ProjectWialonGroup;
use App\Services\WialonGroupClassificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FleetProjectSeeder extends Seeder
{
    public function run(WialonGroupClassificationService $classification): void
    {
        $groups = $classification->projectGroups();
        $projectNames = $classification->projectNames();
        $groupIds = $groups->pluck('wialon_group_id')->map(fn ($id): string => (string) $id)->values();

        ProjectWialonGroup::query()
            ->whereNotIn('wialon_group_id', $groupIds)
            ->delete();

        $projects = $this->syncProjects($projectNames);
        Project::query()
            ->whereNotIn('id', $projects->pluck('id')->all())
            ->update(['active' => false]);

        $this->syncProjectGroups($groups, $projects);
        $this->syncGeofenceGroups($projects);
    }

    private function syncProjects(Collection $projectNames): Collection
    {
        return $projectNames
            ->mapWithKeys(function (string $name): array {
                $project = Project::updateOrCreate(
                    ['name' => $name],
                    [
                        'code' => $this->projectCode($name),
                        'active' => true,
                    ]
                );

                return [$name => $project];
            });
    }

    private function syncProjectGroups(Collection $groups, Collection $projects): void
    {
        foreach ($groups as $group) {
            $project = $projects->get($group['project']);

            if (! $project instanceof Project) {
                continue;
            }

            ProjectWialonGroup::updateOrCreate(
                ['wialon_group_id' => (string) $group['wialon_group_id']],
                [
                    'project_id' => $project->id,
                    'name' => $group['name'],
                    'ownership_type' => $group['ownership_type'],
                ]
            );
        }
    }

    private function syncGeofenceGroups(Collection $projects): void
    {
        foreach (config('wialon_projects.geofence_groups', []) as $group) {
            $project = $projects->get($group['project']);

            if (! $project instanceof Project) {
                continue;
            }

            ProjectWialonGeofenceGroup::updateOrCreate(
                [
                    'wialon_resource_id' => (string) $group['wialon_resource_id'],
                    'wialon_geofence_group_id' => (string) $group['wialon_geofence_group_id'],
                ],
                [
                    'project_id' => $project->id,
                    'wialon_resource_name' => $group['wialon_resource_name'],
                    'name' => $group['name'],
                    'zones_count' => (int) $group['zones_count'],
                ]
            );
        }
    }

    private function projectCode(string $name): string
    {
        return (string) Str::of($name)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->limit(50, '');
    }
}
