<?php

namespace App\Support;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GeofenceExcludedGroups
{
    public const MESSAGE = 'Layihəsiz və Təmir qrupları geozona hesabatlarında istifadə edilmir.';

    /** @var array<int, int>|null */
    private ?array $projectWialonGroupIds = null;

    /** @var array<int, int>|null */
    private ?array $projectIdsWithOnlyExcludedGroups = null;

    /**
     * @return array<int, string>
     */
    public function wialonGroupIds(): array
    {
        return collect(config('fleet.geofence_excluded_group_ids', config('fleet.geofence.excluded_group_ids', [])))
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function groupNames(): array
    {
        return collect(config('fleet.geofence_excluded_group_names', config('fleet.geofence.excluded_group_names', [])))
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function projectNames(): array
    {
        return collect(config('fleet.geofence_excluded_project_names', config('fleet.geofence.excluded_project_names', [])))
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function projectWialonGroupIds(): array
    {
        return $this->projectWialonGroupIds ??= ProjectWialonGroup::query()
            ->get(['id', 'wialon_group_id', 'name'])
            ->filter(fn (ProjectWialonGroup $group): bool => $this->isProjectWialonGroupExcluded($group))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function projectIdsWithOnlyExcludedGroups(): array
    {
        $excludedGroupIds = $this->projectWialonGroupIds();

        $projectIdsByName = Project::query()
            ->get(['id', 'name'])
            ->filter(fn (Project $project): bool => $this->isProjectNameExcluded($project->name))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        $projectIdsByGroups = $excludedGroupIds === []
            ? collect()
            : Project::query()
                ->whereHas('wialonGroups', fn (EloquentBuilder $query): EloquentBuilder => $query->whereIn('id', $excludedGroupIds))
                ->whereDoesntHave('wialonGroups', function (EloquentBuilder $query) use ($excludedGroupIds): void {
                    $query->whereNotIn('id', $excludedGroupIds);

                    if (Schema::hasColumn('project_wialon_groups', 'is_active')) {
                        $query->where('is_active', true);
                    }
                })
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);

        return $this->projectIdsWithOnlyExcludedGroups ??= $projectIdsByName
            ->merge($projectIdsByGroups)
            ->unique()
            ->values()
            ->all();
    }

    public function isProjectWialonGroupExcluded(?ProjectWialonGroup $group): bool
    {
        if (! $group instanceof ProjectWialonGroup) {
            return false;
        }

        return $this->isWialonGroupIdExcluded($group->wialon_group_id)
            || $this->isGroupNameExcluded($group->name);
    }

    public function isWialonGroupIdExcluded(mixed $groupId): bool
    {
        $groupId = trim((string) $groupId);

        return $groupId !== '' && in_array($groupId, $this->wialonGroupIds(), true);
    }

    public function isGroupNameExcluded(mixed $name): bool
    {
        $normalized = $this->normalizeName($name);

        return $normalized !== ''
            && in_array($normalized, collect($this->groupNames())->map(fn (string $value): string => $this->normalizeName($value))->all(), true);
    }

    public function isProjectNameExcluded(mixed $name): bool
    {
        $normalized = $this->normalizeName($name);

        return $normalized !== ''
            && in_array($normalized, collect($this->projectNames())->map(fn (string $value): string => $this->normalizeName($value))->all(), true);
    }

    public function unitMatchesExcludedGroup(?Equipment $unit): bool
    {
        if (! $unit instanceof Equipment) {
            return false;
        }

        return in_array((int) $unit->project_wialon_group_id, $this->projectWialonGroupIds(), true)
            || $this->isWialonGroupIdExcluded($unit->matched_wialon_group_id)
            || in_array((int) $unit->project_id, $this->projectIdsWithOnlyExcludedGroups(), true);
    }

    public function intervalMatchesExcludedGroup(UnitForeignGeofenceInterval $interval): bool
    {
        $sourceGroupIds = collect($interval->source_group_ids_json ?? [])
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->values();

        return $this->isWialonGroupIdExcluded($interval->source_group_id)
            || $sourceGroupIds->contains(fn (string $id): bool => $this->isWialonGroupIdExcluded($id))
            || in_array((int) $interval->home_project_id, $this->projectIdsWithOnlyExcludedGroups(), true)
            || $this->unitMatchesExcludedGroup($interval->unit);
    }

    public function applyAllowedProjectWialonGroups(EloquentBuilder $query): EloquentBuilder
    {
        $excludedGroupIds = $this->projectWialonGroupIds();
        $excludedProjectIds = $this->projectIdsWithOnlyExcludedGroups();

        if ($excludedGroupIds !== []) {
            $query->whereNotIn($query->getModel()->qualifyColumn('id'), $excludedGroupIds);
        }

        if ($excludedProjectIds !== []) {
            $query->whereNotIn($query->getModel()->qualifyColumn('project_id'), $excludedProjectIds);
        }

        return $query;
    }

    public function applyAllowedUnits(EloquentBuilder $query): EloquentBuilder
    {
        $excludedGroupIds = $this->projectWialonGroupIds();
        $excludedWialonIds = $this->wialonGroupIds();
        $excludedProjectIds = $this->projectIdsWithOnlyExcludedGroups();

        if ($excludedGroupIds !== []) {
            $query->where(function (EloquentBuilder $query) use ($excludedGroupIds): void {
                $query->whereNull($query->getModel()->qualifyColumn('project_wialon_group_id'))
                    ->orWhereNotIn($query->getModel()->qualifyColumn('project_wialon_group_id'), $excludedGroupIds);
            });
        }

        if ($excludedWialonIds !== []) {
            $query->where(function (EloquentBuilder $query) use ($excludedWialonIds): void {
                $query->whereNull($query->getModel()->qualifyColumn('matched_wialon_group_id'))
                    ->orWhereNotIn($query->getModel()->qualifyColumn('matched_wialon_group_id'), $excludedWialonIds);
            });
        }

        if ($excludedProjectIds !== []) {
            $query->whereNotIn($query->getModel()->qualifyColumn('project_id'), $excludedProjectIds);
        }

        return $query;
    }

    public function applyAllowedIntervals(EloquentBuilder $query): EloquentBuilder
    {
        $excludedWialonIds = $this->wialonGroupIds();
        $excludedProjectIds = $this->projectIdsWithOnlyExcludedGroups();

        if ($excludedWialonIds !== []) {
            $query->where(function (EloquentBuilder $query) use ($excludedWialonIds): void {
                $query->whereNull('source_group_id')
                    ->orWhereNotIn('source_group_id', $excludedWialonIds);
            });
        }

        if ($excludedProjectIds !== []) {
            $query->whereNotIn('home_project_id', $excludedProjectIds);
        }

        return $query->whereHas('unit', fn (EloquentBuilder $query): EloquentBuilder => $this->applyAllowedUnits($query));
    }

    public function applyAllowedIntervalTables(QueryBuilder $query, string $intervalAlias = 'intervals', ?string $unitAlias = 'units'): QueryBuilder
    {
        $excludedGroupIds = $this->projectWialonGroupIds();
        $excludedWialonIds = $this->wialonGroupIds();
        $excludedProjectIds = $this->projectIdsWithOnlyExcludedGroups();

        if ($excludedWialonIds !== []) {
            $query->where(function (QueryBuilder $query) use ($intervalAlias, $excludedWialonIds): void {
                $query->whereNull($intervalAlias.'.source_group_id')
                    ->orWhereNotIn($intervalAlias.'.source_group_id', $excludedWialonIds);
            });
        }

        if ($excludedProjectIds !== []) {
            $query->whereNotIn($intervalAlias.'.home_project_id', $excludedProjectIds);
        }

        if ($unitAlias !== null) {
            if ($excludedGroupIds !== []) {
                $query->where(function (QueryBuilder $query) use ($unitAlias, $excludedGroupIds): void {
                    $query->whereNull($unitAlias.'.project_wialon_group_id')
                        ->orWhereNotIn($unitAlias.'.project_wialon_group_id', $excludedGroupIds);
                });
            }

            if ($excludedWialonIds !== []) {
                $query->where(function (QueryBuilder $query) use ($unitAlias, $excludedWialonIds): void {
                    $query->whereNull($unitAlias.'.matched_wialon_group_id')
                        ->orWhereNotIn($unitAlias.'.matched_wialon_group_id', $excludedWialonIds);
                });
            }

            if ($excludedProjectIds !== []) {
                $query->whereNotIn($unitAlias.'.project_id', $excludedProjectIds);
            }
        }

        return $query;
    }

    public function invalidateGeofenceCaches(): void
    {
        foreach (['geofence_violations:data_version', 'geofence_transfers:data_version'] as $key) {
            Cache::forever($key, sprintf('%.6F', microtime(true)));
        }
    }

    private function normalizeName(mixed $name): string
    {
        return (string) Str::of((string) $name)
            ->lower()
            ->squish();
    }
}
