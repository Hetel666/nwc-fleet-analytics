<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GeofenceReportViolationCalculator
{
    public const SOURCE = 'wialon_report_api';

    public function __construct(private GeofenceNameNormalizer $normalizer)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function processGroupReport(
        ProjectWialonGroup $group,
        array $records,
        array $context,
        ?string $unitFilter = null,
        bool $persist = true
    ): array {
        $details = [];
        $violations = [];
        $stats = $this->emptyStats();
        $homeProject = $this->resolveHomeProject($group);
        $allowedHomeGeofences = $homeProject instanceof Project
            ? $this->resolveAllowedHomeGeofences($homeProject)
            : collect();

        foreach ($records as $record) {
            if (! $this->recordMatchesUnitFilter($record, $unitFilter)) {
                continue;
            }

            $analysis = $this->analyzeRecord($group, $homeProject, $allowedHomeGeofences, $record, $context);
            $details[] = $analysis['detail'];
            $stats[$analysis['counter']] = ($stats[$analysis['counter']] ?? 0) + 1;

            if (($analysis['detail']['project_mismatch'] ?? false) === true) {
                $stats['project_mismatches']++;
            }

            if (($analysis['detail']['match_status'] ?? null) === 'unresolved') {
                $stats['unresolved_geofences']++;
            }

            if (($analysis['violation'] ?? null) !== null) {
                $violations[] = $analysis['violation'];
            }
        }

        $merged = $this->mergeIntervals($violations);
        $saved = 0;
        $updated = 0;

        if ($persist) {
            foreach ($merged as $violation) {
                $existing = UnitForeignGeofenceInterval::query()
                    ->where('unique_key', $violation['unique_key'])
                    ->first();

                if ($existing instanceof UnitForeignGeofenceInterval) {
                    $existing->fill($this->mergeExistingSourceGroups($existing, $violation))->save();
                    $updated++;
                } else {
                    UnitForeignGeofenceInterval::query()->create($violation);
                    $saved++;
                }
            }
        }

        $threshold = $this->minimumDurationSeconds();
        $underThreshold = collect($merged)->filter(fn (array $row): bool => (int) $row['duration_seconds'] < $threshold)->count();
        $atLeastThreshold = count($merged) - $underThreshold;

        return [
            ...$stats,
            'violations_under_threshold' => $underThreshold,
            'violations_at_least_threshold' => $atLeastThreshold,
            'saved_records' => $saved,
            'updated_records' => $updated,
            'details' => $details,
            'violations' => $merged,
        ];
    }

    public function resolveHomeProject(ProjectWialonGroup $group): ?Project
    {
        return $group->project;
    }

    /**
     * @return Collection<int, Geofence>
     */
    public function resolveAllowedHomeGeofences(Project $project): Collection
    {
        $geofences = Geofence::query()
            ->where('active', true)
            ->where('project_id', $project->id)
            ->with('project:id,name')
            ->get();

        $sharedNames = collect(config('wialon_projects.shared_home_geofences.'.$project->name, []))
            ->map(fn (string $name): string => $this->normalizer->normalize($name))
            ->filter()
            ->values();

        if ($sharedNames->isNotEmpty()) {
            $shared = Geofence::query()
                ->where('active', true)
                ->with('project:id,name')
                ->get()
                ->filter(fn (Geofence $geofence): bool => $sharedNames->contains($this->normalizer->normalize($geofence->name)));
            $geofences = $geofences->merge($shared);
        }

        return $geofences->unique('id')->values();
    }

    /**
     * @return array{geofence: Geofence|null, match_method: string|null, match_status: string, stable_key: string}
     */
    public function resolveVisitedGeofence(?string $wialonGeofenceId, string $name, array $context): array
    {
        $stableId = $this->stableWialonGeofenceId($wialonGeofenceId, $context);

        if ($stableId !== null) {
            $matches = Geofence::query()
                ->where('wialon_geofence_id', $stableId)
                ->with('project:id,name')
                ->get();

            if ($matches->count() === 1) {
                return [
                    'geofence' => $matches->first(),
                    'match_method' => 'wialon_id',
                    'match_status' => 'matched',
                    'stable_key' => $stableId,
                ];
            }

            if ($matches->count() > 1) {
                return [
                    'geofence' => null,
                    'match_method' => 'wialon_id',
                    'match_status' => 'ambiguous',
                    'stable_key' => $stableId,
                ];
            }
        }

        $normalized = $this->normalizer->normalize($name);

        if ($normalized === '') {
            return [
                'geofence' => null,
                'match_method' => null,
                'match_status' => 'unresolved',
                'stable_key' => $stableId ?: 'name:',
            ];
        }

        $matches = Geofence::query()
            ->where('active', true)
            ->with('project:id,name')
            ->get()
            ->filter(fn (Geofence $geofence): bool => $this->normalizer->normalize($geofence->normalized_name ?: $geofence->name) === $normalized)
            ->values();

        if ($matches->count() === 1) {
            return [
                'geofence' => $matches->first(),
                'match_method' => 'normalized_name',
                'match_status' => 'matched',
                'stable_key' => 'name:'.$normalized,
            ];
        }

        return [
            'geofence' => null,
            'match_method' => 'normalized_name',
            'match_status' => $matches->count() > 1 ? 'ambiguous' : 'unresolved',
            'stable_key' => 'name:'.$normalized,
        ];
    }

    /**
     * @param  Collection<int, Geofence>  $allowedHomeGeofences
     */
    public function isHomeGeofence(Collection $allowedHomeGeofences, ?Geofence $visitedGeofence, string $visitedName, ?string $visitedWialonId, array $context): bool
    {
        $stableVisitedId = $this->stableWialonGeofenceId($visitedWialonId, $context);
        $normalizedVisitedName = $this->normalizer->normalize($visitedName);

        return $allowedHomeGeofences->contains(function (Geofence $homeGeofence) use ($visitedGeofence, $stableVisitedId, $normalizedVisitedName): bool {
            if ($visitedGeofence instanceof Geofence && (int) $homeGeofence->id === (int) $visitedGeofence->id) {
                return true;
            }

            if ($stableVisitedId !== null && $homeGeofence->wialon_geofence_id === $stableVisitedId) {
                return true;
            }

            return $normalizedVisitedName !== ''
                && $this->normalizer->normalize($homeGeofence->normalized_name ?: $homeGeofence->name) === $normalizedVisitedName;
        });
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $context
     * @param  Collection<int, Geofence>  $allowedHomeGeofences
     * @return array<string, mixed>
     */
    private function analyzeRecord(
        ProjectWialonGroup $group,
        ?Project $homeProject,
        Collection $allowedHomeGeofences,
        array $record,
        array $context
    ): array {
        $unit = $this->resolveUnit($record, $group);
        $detail = $this->baseDetail($group, $homeProject, $allowedHomeGeofences, $record, $unit);

        if (($record['invalid_reason'] ?? null) !== null) {
            return $this->excluded($detail, (string) $record['invalid_reason'], 'invalid_rows');
        }

        if (! $homeProject instanceof Project) {
            return $this->excluded($detail, 'missing_home_project', 'missing_home_project');
        }

        if ($allowedHomeGeofences->isEmpty()) {
            return $this->excluded($detail, 'missing_home_geofence', 'missing_home_geofence');
        }

        if (! $unit instanceof Equipment) {
            return $this->excluded($detail, 'missing_unit', 'unresolved_units');
        }

        $visited = $this->resolveVisitedGeofence(
            $record['visited_geofence_id'] ?? null,
            (string) ($record['visited_geofence_name'] ?? ''),
            $context
        );
        $detail['match_method'] = $visited['match_method'];
        $detail['match_status'] = $visited['match_status'];
        $detail['foreign_project'] = $visited['geofence']?->project?->name ?? '';

        $isHome = $this->isHomeGeofence(
            $allowedHomeGeofences,
            $visited['geofence'],
            (string) ($record['visited_geofence_name'] ?? ''),
            $record['visited_geofence_id'] ?? null,
            $context
        );

        if ($isHome) {
            return $this->excluded($detail, 'home_geofence_visit', 'home_visits', true);
        }

        if ($visited['match_status'] === 'ambiguous') {
            return $this->excluded($detail, 'ambiguous_geofence_match', 'ambiguous_geofences');
        }

        $projectMismatch = $this->projectMismatch($homeProject, (string) ($record['reported_project'] ?? ''));
        $reason = $visited['geofence']?->project instanceof Project
            ? ($projectMismatch ? 'project_mismatch_but_foreign_geofence_confirmed' : 'foreign_geofence_visit')
            : 'foreign_geofence_without_project';
        $detail['included'] = true;
        $detail['reason'] = $reason;
        $detail['project_mismatch'] = $projectMismatch;

        return [
            'counter' => 'foreign_visits',
            'detail' => $detail,
            'violation' => $this->violationRow(
                $group,
                $homeProject,
                $allowedHomeGeofences,
                $unit,
                $record,
                $context,
                $visited,
                $reason,
                $projectMismatch
            ),
        ];
    }

    /**
     * @param  Collection<int, Geofence>  $allowedHomeGeofences
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $visited
     * @return array<string, mixed>
     */
    private function violationRow(
        ProjectWialonGroup $group,
        Project $homeProject,
        Collection $allowedHomeGeofences,
        Equipment $unit,
        array $record,
        array $context,
        array $visited,
        string $reason,
        bool $projectMismatch
    ): array {
        $enteredAt = $record['entry_at'];
        $leftAt = $record['exit_at'];
        $durationSeconds = (int) $record['duration_seconds'];
        $foreignGeofence = $visited['geofence'];
        $foreignProject = $foreignGeofence?->project;
        $sourceGroupIds = [(string) $group->wialon_group_id];
        $stableKey = $visited['stable_key'];

        $row = [
            'unit_id' => $unit->id,
            'wialon_unit_id' => (string) ($unit->wialon_unit_id ?: ($record['wialon_unit_id'] ?? '')),
            'source_group_id' => (string) $group->wialon_group_id,
            'source_group_name' => $group->name,
            'source_group_ids_json' => $sourceGroupIds,
            'ownership_type' => $group->ownership_type,
            'home_project_id' => $homeProject->id,
            'home_project_name' => $homeProject->name,
            'home_geofence_id' => $allowedHomeGeofences->first()?->id,
            'home_geofence_ids_json' => $allowedHomeGeofences->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'home_geofence_names_json' => $allowedHomeGeofences->pluck('name')->values()->all(),
            'foreign_project_id' => $foreignProject?->id,
            'foreign_project_name' => $foreignProject?->name,
            'foreign_geofence_id' => $foreignGeofence?->id,
            'foreign_geofence_name' => $foreignGeofence?->name ?? (string) ($record['visited_geofence_name'] ?? ''),
            'entered_at' => $enteredAt,
            'left_at' => $leftAt,
            'duration_seconds' => $durationSeconds,
            'status' => UnitForeignGeofenceInterval::STATUS_CLOSED,
            'last_position_at' => $leftAt,
            'report_from' => $context['from'] ?? null,
            'report_to' => $context['to'] ?? null,
            'report_resource_id' => (string) ($context['resource_id'] ?? ''),
            'report_template_id' => (string) ($context['template_id'] ?? ''),
            'report_table_name' => (string) ($context['table_name'] ?? ''),
            'reported_project' => (string) ($record['reported_project'] ?? ''),
            'project_mismatch' => $projectMismatch,
            'match_method' => $visited['match_method'],
            'match_status' => $visited['match_status'],
            'reason' => $reason,
            'source' => self::SOURCE,
            'calculated_at' => now(config('app.timezone')),
            '_stable_geofence_key' => $stableKey,
        ];

        $row['unique_key'] = $this->uniqueKey($row);

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     * @return array<int, array<string, mixed>>
     */
    public function mergeIntervals(array $violations): array
    {
        $gapSeconds = max(0, (int) config('fleet.foreign_geofence.merge_gap_minutes', 5)) * 60;
        $groups = collect($violations)->groupBy(fn (array $row): string => implode('|', [
            $row['wialon_unit_id'] ?? '',
            $row['home_project_id'] ?? '',
            $row['_stable_geofence_key'] ?? '',
            $row['source_group_id'] ?? '',
        ]));
        $merged = [];

        foreach ($groups as $rows) {
            $sorted = $rows->sortBy(fn (array $row): int => $row['entered_at'] instanceof CarbonInterface ? $row['entered_at']->timestamp : 0)->values();
            $current = null;

            foreach ($sorted as $row) {
                if ($current === null) {
                    $current = $row;
                    continue;
                }

                $currentEnd = $current['left_at'];
                $nextStart = $row['entered_at'];

                if ($currentEnd instanceof CarbonInterface && $nextStart instanceof CarbonInterface && $nextStart->timestamp <= $currentEnd->timestamp + $gapSeconds) {
                    if ($row['left_at'] instanceof CarbonInterface && $row['left_at']->greaterThan($currentEnd)) {
                        $current['left_at'] = $row['left_at'];
                        $current['last_position_at'] = $row['left_at'];
                    }

                    $current['duration_seconds'] = (int) $current['entered_at']->diffInSeconds($current['left_at'], true);
                    $current['source_group_ids_json'] = collect([
                        ...($current['source_group_ids_json'] ?? []),
                        ...($row['source_group_ids_json'] ?? []),
                    ])->filter()->unique()->values()->all();
                    $current['unique_key'] = $this->uniqueKey($current);
                    continue;
                }

                $merged[] = $this->cleanPersistedRow($current);
                $current = $row;
            }

            if ($current !== null) {
                $merged[] = $this->cleanPersistedRow($current);
            }
        }

        return $merged;
    }

    private function resolveUnit(array $record, ProjectWialonGroup $group): ?Equipment
    {
        $wialonUnitId = trim((string) ($record['wialon_unit_id'] ?? ''));

        if ($wialonUnitId !== '') {
            return Equipment::query()->where('wialon_unit_id', $wialonUnitId)->first();
        }

        $unitName = trim((string) ($record['unit_name'] ?? ''));

        if ($unitName === '') {
            return null;
        }

        $matches = Equipment::query()
            ->where('name', $unitName)
            ->where(function ($query) use ($group): void {
                $query->where('project_wialon_group_id', $group->id)
                    ->orWhere('matched_wialon_group_id', (string) $group->wialon_group_id)
                    ->orWhere(function ($query) use ($group): void {
                        $query->where('project_id', $group->project_id)
                            ->where('ownership_type', $group->ownership_type);
                    });
            })
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function projectMismatch(Project $homeProject, string $reportedProject): bool
    {
        $reportedProject = $this->normalizer->normalize($reportedProject);

        return $reportedProject !== '' && $reportedProject !== $this->normalizer->normalize($homeProject->name);
    }

    private function stableWialonGeofenceId(?string $wialonGeofenceId, array $context): ?string
    {
        $id = trim((string) $wialonGeofenceId);

        if ($id === '') {
            return null;
        }

        if (str_contains($id, ':')) {
            return $id;
        }

        $resourceId = trim((string) ($context['resource_id'] ?? ''));

        return $resourceId !== '' ? $resourceId.':'.$id : $id;
    }

    /**
     * @param  Collection<int, Geofence>  $allowedHomeGeofences
     * @return array<string, mixed>
     */
    private function baseDetail(
        ProjectWialonGroup $group,
        ?Project $homeProject,
        Collection $allowedHomeGeofences,
        array $record,
        ?Equipment $unit
    ): array {
        return [
            'group_id' => (string) $group->wialon_group_id,
            'group_name' => $group->name,
            'expected_home_project' => $homeProject?->name ?? '',
            'allowed_home_geofences' => $allowedHomeGeofences->pluck('name')->values()->all(),
            'parent_geofence' => (string) ($record['visited_geofence_name'] ?? ''),
            'unit_name' => (string) ($record['unit_name'] ?? ''),
            'wialon_unit_id' => (string) ($record['wialon_unit_id'] ?? $unit?->wialon_unit_id ?? ''),
            'reported_project' => (string) ($record['reported_project'] ?? ''),
            'entry_time' => $record['entry_at'] ?? null,
            'exit_time' => $record['exit_at'] ?? null,
            'duration_seconds' => $record['duration_seconds'] ?? null,
            'is_home_geofence' => false,
            'foreign_project' => '',
            'included' => false,
            'reason' => '',
            'match_method' => null,
            'match_status' => null,
            'project_mismatch' => false,
        ];
    }

    private function excluded(array $detail, string $reason, string $counter, bool $isHome = false): array
    {
        $detail['reason'] = $reason;
        $detail['included'] = false;
        $detail['is_home_geofence'] = $isHome;

        return [
            'counter' => $counter,
            'detail' => $detail,
            'violation' => null,
        ];
    }

    private function uniqueKey(array $row): string
    {
        $enteredAt = $row['entered_at'] instanceof CarbonInterface ? $row['entered_at']->timestamp : (string) ($row['entered_at'] ?? '');

        return sha1(implode('|', [
            self::SOURCE,
            $row['wialon_unit_id'] ?? '',
            $row['home_project_id'] ?? '',
            $row['_stable_geofence_key'] ?? $row['foreign_geofence_id'] ?? $row['foreign_geofence_name'] ?? '',
            $enteredAt,
        ]));
    }

    private function cleanPersistedRow(array $row): array
    {
        unset($row['_stable_geofence_key']);

        return $row;
    }

    private function mergeExistingSourceGroups(UnitForeignGeofenceInterval $existing, array $row): array
    {
        $row['source_group_ids_json'] = collect([
            ...($existing->source_group_ids_json ?? []),
            ...($row['source_group_ids_json'] ?? []),
        ])->filter()->unique()->values()->all();

        return $row;
    }

    private function recordMatchesUnitFilter(array $record, ?string $unitFilter): bool
    {
        $unitFilter = trim((string) $unitFilter);

        if ($unitFilter === '') {
            return true;
        }

        return (string) ($record['wialon_unit_id'] ?? '') === $unitFilter
            || (string) ($record['unit_name'] ?? '') === $unitFilter;
    }

    private function minimumDurationSeconds(): int
    {
        return max(0, (int) config('fleet.foreign_geofence.min_minutes', 180)) * 60;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'home_visits' => 0,
            'foreign_visits' => 0,
            'missing_home_project' => 0,
            'missing_home_geofence' => 0,
            'unresolved_units' => 0,
            'unresolved_geofences' => 0,
            'ambiguous_geofences' => 0,
            'project_mismatches' => 0,
            'invalid_rows' => 0,
        ];
    }
}
