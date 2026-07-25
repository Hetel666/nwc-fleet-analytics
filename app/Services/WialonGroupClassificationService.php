<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WialonGroupClassificationService
{
    private ?Collection $projectMappingsByGroupId = null;

    public function projectNames(): Collection
    {
        return collect(config('wialon_projects.projects', []))
            ->pluck('name')
            ->unique()
            ->values();
    }

    public function projectGroups(): Collection
    {
        return collect(config('wialon_projects.projects', []))
            ->flatMap(function (array $project): array {
                return array_filter([
                    $this->configuredProjectGroup($project, Equipment::OWNERSHIP_NWC),
                    $this->configuredProjectGroup($project, Equipment::OWNERSHIP_ICARE),
                ]);
            })
            ->values();
    }

    public function projectGroupIds(): array
    {
        return $this->activeProjectGroupQuery()
            ->pluck('wialon_group_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    public function serviceGroupIds(): array
    {
        return $this->serviceGroups()
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    public function classificationGroupIds(): array
    {
        return array_values(array_unique(array_merge($this->projectGroupIds(), $this->serviceGroupIds())));
    }

    public function serviceGroups(): Collection
    {
        $groups = config('wialon_projects.service_groups', []);

        return collect($groups['without_project'] ?? [])
            ->merge($groups['totals'] ?? [])
            ->map(fn (array $group): array => [
                'id' => (string) $group['id'],
                'name' => $group['name'],
                'ownership_type' => $group['ownership_type'],
            ])
            ->values();
    }

    public function isServiceGroup(string|int|null $groupId): bool
    {
        return in_array((string) $groupId, $this->serviceGroupIds(), true);
    }

    public function isWithoutProjectGroup(string|int|null $groupId): bool
    {
        return $this->withoutProjectGroups()->has((string) $groupId);
    }

    public function isTotalGroup(string|int|null $groupId): bool
    {
        return $this->totalGroups()->has((string) $groupId);
    }

    public function isPassengerCarGroup(string|int|null $groupId): bool
    {
        return (string) $groupId === (string) data_get(config('wialon_projects.service_groups.totals.nwc_passenger_car'), 'id');
    }

    /**
     * @param  list<array{id:string,name:string}>  $groups
     */
    public function classifyUnit(array $groups, ?string $unitId = null): array
    {
        $groupsById = collect($groups)
            ->map(fn (array $group): array => [
                'id' => (string) ($group['id'] ?? ''),
                'name' => (string) ($group['name'] ?? $group['nm'] ?? ''),
            ])
            ->filter(fn (array $group): bool => $group['id'] !== '')
            ->unique('id')
            ->keyBy(fn (array $group): string => $this->groupLookupKey($group['id']));

        $projectCandidates = $this->projectCandidates($groupsById);

        if ($projectCandidates->isNotEmpty()) {
            return $this->resolveProjectCandidate($projectCandidates, $unitId);
        }

        foreach ([$this->withoutProjectGroups(), $this->totalGroups()] as $serviceGroups) {
            foreach ($groupsById as $group) {
                $serviceGroup = $serviceGroups->get((string) $group['id']);

                if ($serviceGroup) {
                    return $this->classification(
                        null,
                        null,
                        $serviceGroup['ownership_type'],
                        (string) $group['id'],
                        $group['name'] ?: $serviceGroup['name'],
                        $this->withoutProjectGroups()->has((string) $group['id']),
                        false
                    );
                }
            }
        }

        return $this->classification(null, null, null, null, null, false, false);
    }

    public function resolveProject(string|int|null $groupId): ?Project
    {
        $group = ProjectWialonGroup::query()
            ->where('wialon_group_id', (string) $groupId)
            ->with('project')
            ->first();

        return $group?->project;
    }

    public function resolveOwnership(string|int|null $groupId): ?string
    {
        $projectGroup = ProjectWialonGroup::query()->where('wialon_group_id', (string) $groupId)->first();

        if ($projectGroup) {
            return $projectGroup->ownership_type;
        }

        return $this->serviceGroups()->firstWhere('id', (string) $groupId)['ownership_type'] ?? null;
    }

    private function configuredProjectGroup(array $project, string $ownershipType): ?array
    {
        $prefix = $ownershipType === Equipment::OWNERSHIP_NWC ? 'nwc' : 'icare';
        $groupId = $project[$prefix.'_group_id'] ?? null;

        if ($groupId === null || $groupId === '') {
            return null;
        }

        return [
            'project' => $project['name'],
            'wialon_group_id' => (string) $groupId,
            'name' => $project[$prefix.'_group_name'],
            'ownership_type' => $ownershipType,
        ];
    }

    private function withoutProjectGroups(): Collection
    {
        return collect(config('wialon_projects.service_groups.without_project', []))
            ->mapWithKeys(fn (array $group): array => [(string) $group['id'] => $group]);
    }

    private function totalGroups(): Collection
    {
        return collect(config('wialon_projects.service_groups.totals', []))
            ->mapWithKeys(fn (array $group): array => [(string) $group['id'] => $group]);
    }

    private function projectCandidates(Collection $groupsById): Collection
    {
        return $this->projectMappingsByGroupId()
            ->only($groupsById->keys()->all())
            ->map(function (ProjectWialonGroup $group) use ($groupsById): array {
                $wialonGroup = $groupsById->get($this->groupLookupKey($group->wialon_group_id));

                return [
                    'project_id' => $group->project_id,
                    'project_name' => $group->project?->name,
                    'project_wialon_group_id' => $group->id,
                    'ownership' => $group->ownership_type,
                    'matched_group_id' => (string) $group->wialon_group_id,
                    'matched_group_name' => (string) ($wialonGroup['name'] ?: $group->name),
                ];
            })
            ->values();
    }

    private function projectMappingsByGroupId(): Collection
    {
        if ($this->projectMappingsByGroupId !== null) {
            return $this->projectMappingsByGroupId;
        }

        return $this->projectMappingsByGroupId = $this->activeProjectGroupQuery()
            ->with('project:id,name,active')
            ->get()
            ->toBase()
            ->mapWithKeys(fn (ProjectWialonGroup $group): array => [
                $this->groupLookupKey($group->wialon_group_id) => $group,
            ]);
    }

    private function activeProjectGroupQuery(): Builder
    {
        return ProjectWialonGroup::query()
            ->whereHas('project', fn (Builder $query): Builder => $query->where('active', true))
            ->when(
                Schema::hasColumn('project_wialon_groups', 'is_active'),
                fn (Builder $query): Builder => $query->where('is_active', true)
            );
    }

    private function groupLookupKey(string|int $groupId): string
    {
        return 'g:'.(string) $groupId;
    }

    private function resolveProjectCandidate(Collection $candidates, ?string $unitId): array
    {
        $projectIds = $candidates->pluck('project_id')->unique()->values();

        if ($projectIds->count() > 1) {
            Log::warning('Unit belongs to multiple project groups', [
                'unit_id' => $unitId,
                'group_ids' => $candidates->pluck('matched_group_id')->all(),
            ]);

            return $this->classification(null, null, null, null, null, false, true);
        }

        $selected = $candidates
            ->sortBy(fn (array $candidate): string => ($candidate['ownership'] === Equipment::OWNERSHIP_NWC ? '0' : '1').'-'.$candidate['matched_group_id'])
            ->first();

        if ($candidates->pluck('ownership')->unique()->count() > 1) {
            Log::warning('Unit belongs to both NWC and ICARE groups in one project', [
                'unit_id' => $unitId,
                'project_id' => $selected['project_id'],
                'group_ids' => $candidates->pluck('matched_group_id')->all(),
            ]);
        }

        return $this->classification(
            $selected['project_id'],
            $selected['project_name'],
            $selected['ownership'],
            $selected['matched_group_id'],
            $selected['matched_group_name'],
            false,
            false,
            $selected['project_wialon_group_id']
        );
    }

    private function classification(
        ?int $projectId,
        ?string $projectName,
        ?string $ownership,
        ?string $matchedGroupId,
        ?string $matchedGroupName,
        bool $withoutProject,
        bool $conflict,
        ?int $projectWialonGroupId = null
    ): array {
        return [
            'project_id' => $projectId,
            'project_name' => $projectName,
            'project_wialon_group_id' => $projectWialonGroupId,
            'ownership' => $ownership,
            'matched_group_id' => $matchedGroupId,
            'matched_group_name' => $matchedGroupName,
            'without_project' => $withoutProject,
            'conflict' => $conflict,
        ];
    }
}
