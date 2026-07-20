<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGeofenceGroup;
use App\Models\ProjectWialonGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('projects.index', [
            'projects' => Project::query()
                ->where('active', true)
                ->withCount([
                    'equipment',
                    'equipment as nwc_equipment_count' => fn (Builder $query) => $query->where('ownership_type', Equipment::OWNERSHIP_NWC),
                    'equipment as icare_equipment_count' => fn (Builder $query) => $query->where('ownership_type', Equipment::OWNERSHIP_ICARE),
                    'equipment as online_equipment_count' => fn (Builder $query) => $query->where('last_synced_at', '>=', now()->subMinutes(15)),
                    'equipment as offline_equipment_count' => fn (Builder $query) => $query->where(function (Builder $query): void {
                        $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(15));
                    }),
                    'geofences',
                    'wialonGroups',
                    'wialonGeofenceGroups',
                ])
                ->orderBy('name')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('projects.form', [
            'project' => new Project(),
            'wialonGroups' => [],
            'wialonGeofenceGroup' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = Project::create($this->validated($request));
        $this->syncWialonGroups($project, $this->validatedWialonGroups($request));
        $this->syncWialonGeofenceGroup($project, $this->validatedWialonGeofenceGroup($request));

        return redirect()->route('projects.index')->with('status', __('app.saved'));
    }

    public function edit(Project $project): View
    {
        $project->load(['wialonGroups', 'wialonGeofenceGroups']);

        return view('projects.form', [
            'project' => $project,
            'wialonGroups' => $this->wialonGroupValues($project),
            'wialonGeofenceGroup' => $this->wialonGeofenceGroupValues($project),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->load(['wialonGroups', 'wialonGeofenceGroups']);
        $project->update($this->validated($request));
        $this->syncWialonGroups($project, $this->validatedWialonGroups($request, $project));
        $this->syncWialonGeofenceGroup($project, $this->validatedWialonGeofenceGroup($request));

        return redirect()->route('projects.index')->with('status', __('app.saved'));
    }

    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return redirect()->route('projects.index')->with('status', __('app.deleted'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');

        return $data;
    }

    private function validatedWialonGroups(Request $request, ?Project $project = null): array
    {
        return $request->validate([
            'wialon_group_nwc' => ['nullable', 'string', 'max:100', $this->uniqueGroupRule($project, Equipment::OWNERSHIP_NWC)],
            'wialon_group_icare' => ['nullable', 'string', 'max:100', $this->uniqueGroupRule($project, Equipment::OWNERSHIP_ICARE)],
        ]);
    }

    private function validatedWialonGeofenceGroup(Request $request): array
    {
        return $request->validate([
            'wialon_geofence_resource_id' => ['nullable', 'required_with:wialon_geofence_group_id', 'string', 'max:100'],
            'wialon_geofence_group_id' => ['nullable', 'required_with:wialon_geofence_resource_id', 'string', 'max:100'],
            'wialon_geofence_group_name' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function uniqueGroupRule(?Project $project, string $ownership): Unique
    {
        $currentGroupId = $project?->wialonGroups->firstWhere('ownership_type', $ownership)?->id;

        return Rule::unique('project_wialon_groups', 'wialon_group_id')->ignore($currentGroupId);
    }

    private function syncWialonGroups(Project $project, array $groups): void
    {
        foreach ([Equipment::OWNERSHIP_NWC => 'wialon_group_nwc', Equipment::OWNERSHIP_ICARE => 'wialon_group_icare'] as $ownership => $field) {
            $groupId = trim((string) ($groups[$field] ?? ''));

            if ($groupId === '') {
                ProjectWialonGroup::query()
                    ->where('project_id', $project->id)
                    ->where('ownership_type', $ownership)
                    ->delete();

                continue;
            }

            ProjectWialonGroup::updateOrCreate(
                ['project_id' => $project->id, 'ownership_type' => $ownership],
                [
                    'wialon_group_id' => $groupId,
                    'name' => $project->name.' - '.$this->ownershipLabel($ownership),
                ]
            );
        }
    }

    private function wialonGroupValues(Project $project): array
    {
        return $project->wialonGroups
            ->mapWithKeys(fn (ProjectWialonGroup $group): array => [$group->ownership_type => $group->wialon_group_id])
            ->all();
    }

    private function syncWialonGeofenceGroup(Project $project, array $group): void
    {
        $resourceId = trim((string) ($group['wialon_geofence_resource_id'] ?? ''));
        $groupId = trim((string) ($group['wialon_geofence_group_id'] ?? ''));

        if ($resourceId === '' && $groupId === '') {
            ProjectWialonGeofenceGroup::query()->where('project_id', $project->id)->delete();

            return;
        }

        ProjectWialonGeofenceGroup::updateOrCreate(
            ['project_id' => $project->id],
            [
                'wialon_resource_id' => $resourceId,
                'wialon_resource_name' => null,
                'wialon_geofence_group_id' => $groupId,
                'name' => ($group['wialon_geofence_group_name'] ?? '') ?: $project->name,
            ]
        );
    }

    private function wialonGeofenceGroupValues(Project $project): array
    {
        $group = $project->wialonGeofenceGroups->first();

        if (! $group instanceof ProjectWialonGeofenceGroup) {
            return [];
        }

        return [
            'wialon_geofence_resource_id' => $group->wialon_resource_id,
            'wialon_geofence_group_id' => $group->wialon_geofence_group_id,
            'wialon_geofence_group_name' => $group->name,
        ];
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İcarə' : 'NWC';
    }
}
