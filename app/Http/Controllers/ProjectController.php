<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\WialonUnitGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
            'wialonGroupOptions' => $this->wialonCatalogGroups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = Project::create($this->validated($request));
        $this->syncWialonGroups($project, $this->validatedWialonGroups($request));

        return redirect()->route('projects.index')->with('status', __('app.saved'));
    }

    public function edit(Project $project): View
    {
        $project->load(['wialonGroups']);

        return view('projects.form', [
            'project' => $project,
            'wialonGroups' => $this->wialonGroupValues($project),
            'wialonGroupOptions' => $this->wialonCatalogGroups(),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->load(['wialonGroups']);
        $project->update($this->validated($request));
        $this->syncWialonGroups($project, $this->validatedWialonGroups($request, $project));

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

    private function wialonCatalogGroups()
    {
        if (! Schema::hasTable('wialon_unit_groups')) {
            return collect();
        }

        return WialonUnitGroup::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['wialon_group_id', 'name', 'units_count', 'linked_project_id', 'ownership_type']);
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İcarə' : 'NWC';
    }
}
