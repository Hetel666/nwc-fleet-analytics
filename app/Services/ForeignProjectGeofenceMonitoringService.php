<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\UnitForeignGeofenceInterval;
use App\Support\FleetVehicleType;
use App\Support\ForeignGeofenceSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ForeignProjectGeofenceMonitoringService
{
    /** @var Collection<int, Geofence>|null */
    private ?Collection $activeGeofences = null;

    /** @var array<int, Collection<int, Geofence>> */
    private array $allowedHomeGeofencesByProjectId = [];

    /** @var array<string, string> */
    private array $normalizedNames = [];

    /**
     * Updates the current foreign-project geofence interval for one unit position.
     *
     * @param  array{lat?: mixed, lng?: mixed, time?: mixed}  $position
     */
    public function processUnitPosition(Equipment $unit, ?array $position): ?UnitForeignGeofenceInterval
    {
        $openIntervals = $this->openIntervals($unit);
        $openInterval = $openIntervals->first();
        $analysis = $this->analyzeUnitPosition($unit, $position);

        if (! $analysis['has_valid_position']) {
            return null;
        }

        if (! $analysis['is_foreign_project_geofence']) {
            $this->closeForeignGeofenceIntervals($openIntervals, $position, $analysis['position_at']);

            return null;
        }

        $positionAt = $analysis['position_at'];
        $homeGeofence = $analysis['home_geofence'];
        $currentGeofence = $analysis['selected_current_geofence'];

        if (
            $openInterval instanceof UnitForeignGeofenceInterval
            && (int) $openInterval->foreign_geofence_id === (int) $currentGeofence->id
        ) {
            $this->closeForeignGeofenceIntervals($openIntervals->skip(1), $position, $positionAt);
            $lastPositionAt = $openInterval->last_position_at && $openInterval->last_position_at->gt($positionAt)
                ? $openInterval->last_position_at
                : $positionAt;

            $openInterval->update(['last_position_at' => $lastPositionAt]);

            return $openInterval;
        }

        return $this->openForeignGeofenceInterval($unit, $homeGeofence, $currentGeofence, $position, $positionAt);
    }

    public function resolveHomeProject(Equipment $unit): ?Project
    {
        return $unit->project ?: ($unit->project_id ? Project::query()->find($unit->project_id) : null);
    }

    public function resolveHomeGeofence(Equipment $unit, ?array $position = null): ?Geofence
    {
        $homeGeofences = $this->homeProjectGeofences($unit);

        if ($this->validPosition($position)) {
            return $homeGeofences
                ->first(fn (Geofence $geofence): bool => $this->containsPosition($geofence, $position));
        }

        return $homeGeofences->first();
    }

    public function resolveCurrentProjectGeofence(?array $position, ?int $excludeProjectId = null): ?Geofence
    {
        $geofences = $this->resolveCurrentProjectGeofences($position);

        if ($excludeProjectId !== null) {
            $geofences = $geofences
                ->reject(fn (Geofence $geofence): bool => (int) $geofence->project_id === (int) $excludeProjectId)
                ->values();
        }

        return $this->chooseSmallestGeofence($geofences);
    }

    /**
     * @return Collection<int, Geofence>
     */
    public function resolveAllowedHomeGeofences(Project $project): Collection
    {
        $projectId = (int) $project->id;

        if (array_key_exists($projectId, $this->allowedHomeGeofencesByProjectId)) {
            return $this->allowedHomeGeofencesByProjectId[$projectId];
        }

        $projectName = $this->normalizedName($project->name);
        $sharedNames = collect(config('wialon_projects.shared_home_geofences', []))
            ->filter(fn (array $projects): bool => collect($projects)
                ->map(fn (string $name): string => $this->normalizedName($name))
                ->contains($projectName))
            ->keys()
            ->map(fn (string $name): string => $this->normalizedName($name))
            ->values();

        return $this->allowedHomeGeofencesByProjectId[$projectId] = $this->activeGeofences()
            ->filter(fn (Geofence $geofence): bool => (int) $geofence->project_id === (int) $project->id
                || $sharedNames->contains($this->normalizedName($geofence->name)))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, Geofence>
     */
    public function resolveCurrentProjectGeofences(?array $position): Collection
    {
        if (! $this->validPosition($position)) {
            return collect();
        }

        return $this->activeGeofences()
            ->filter(fn (Geofence $geofence): bool => $geofence->project_id !== null)
            ->filter(fn (Geofence $geofence): bool => $this->containsPosition($geofence, $position))
            ->sortBy([
                fn (Geofence $a, Geofence $b): int => $this->geofenceArea($a) <=> $this->geofenceArea($b),
                fn (Geofence $a, Geofence $b): int => $a->id <=> $b->id,
            ])
            ->values();
    }

    public function isInsideAllowedHomeGeofence(Equipment $unit, ?array $position): bool
    {
        if (! $this->validPosition($position)) {
            return false;
        }

        return $this->homeProjectGeofences($unit)
            ->contains(fn (Geofence $geofence): bool => $this->containsPosition($geofence, $position));
    }

    public function resolveForeignGeofence(Equipment $unit, ?array $position): ?Geofence
    {
        $analysis = $this->analyzeUnitPosition($unit, $position);

        return $analysis['is_foreign_project_geofence']
            ? $analysis['selected_current_geofence']
            : null;
    }

    /**
     * @return array{
     *     can_monitor: bool,
     *     is_foreign_project_geofence: bool,
     *     reason: string,
     *     position_at: ?Carbon,
     *     has_valid_position: bool,
     *     has_fresh_position: bool,
     *     home_geofence: ?Geofence,
     *     home_geofences: Collection<int, Geofence>,
     *     current_geofence: ?Geofence,
     *     selected_current_geofence: ?Geofence,
     *     current_geofences: Collection<int, Geofence>
     * }
     */
    public function analyzeUnitPosition(Equipment $unit, ?array $position = null): array
    {
        $unit->loadMissing('type', 'project');
        $position ??= is_array($unit->last_position_json) ? $unit->last_position_json : null;
        $hasValidPosition = $this->validPosition($position);
        $positionAt = $hasValidPosition ? $this->positionTime($position) : $this->positionTimestamp($position);
        $hasFreshPosition = $hasValidPosition && $this->positionIsFresh($position);

        $unitExclusionReason = $this->unitExclusionReason($unit);

        if ($unitExclusionReason !== null) {
            return $this->analysis(false, false, $unitExclusionReason, $positionAt, $hasValidPosition, $hasFreshPosition);
        }

        $homeGeofences = $this->homeProjectGeofences($unit);
        $homeGeofence = $homeGeofences->first();

        if ($homeGeofences->isEmpty()) {
            return $this->analysis(true, false, 'missing_home_geofence', $positionAt, $hasValidPosition, $hasFreshPosition);
        }

        if (! $hasValidPosition) {
            return $this->analysis(
                true,
                false,
                $this->invalidPositionReason($position),
                $positionAt,
                false,
                false,
                $homeGeofence,
                null,
                $homeGeofences
            );
        }

        $currentGeofences = $this->resolveCurrentProjectGeofences($position);
        $insideHomeGeofence = $currentGeofences
            ->first(fn (Geofence $geofence): bool => $homeGeofences->contains('id', $geofence->id));

        if ($insideHomeGeofence instanceof Geofence) {
            return $this->analysis(
                true,
                false,
                'inside_home_geofence',
                $positionAt,
                true,
                $hasFreshPosition,
                $insideHomeGeofence,
                $insideHomeGeofence,
                $homeGeofences,
                $currentGeofences,
                $insideHomeGeofence
            );
        }

        $foreignGeofences = $currentGeofences
            ->filter(fn (Geofence $geofence): bool => (int) $geofence->project_id !== (int) $unit->project_id)
            ->values();
        $currentGeofence = $this->chooseSmallestGeofence($foreignGeofences);

        if (! $currentGeofence instanceof Geofence) {
            return $this->analysis(
                true,
                false,
                'outside_all_project_geofences',
                $positionAt,
                true,
                $hasFreshPosition,
                $homeGeofence,
                null,
                $homeGeofences,
                $currentGeofences
            );
        }

        return $this->analysis(
            true,
            true,
            'inside_foreign_project_geofence',
            $positionAt,
            true,
            $hasFreshPosition,
            $homeGeofence,
            $currentGeofence,
            $homeGeofences,
            $currentGeofences,
            $currentGeofence
        );
    }

    public function openForeignGeofenceInterval(
        Equipment $unit,
        ?Geofence $homeGeofence,
        Geofence $foreignGeofence,
        array $position,
        Carbon $enteredAt
    ): UnitForeignGeofenceInterval {
        $this->closeForeignGeofenceIntervals($this->openIntervals($unit), $position, $enteredAt);

        return UnitForeignGeofenceInterval::query()->create([
            'unit_id' => $unit->id,
            'home_project_id' => $unit->project_id,
            'home_geofence_id' => $homeGeofence?->id,
            'foreign_project_id' => $foreignGeofence->project_id,
            'foreign_geofence_id' => $foreignGeofence->id,
            'entered_at' => $enteredAt,
            'status' => UnitForeignGeofenceInterval::STATUS_OPEN,
            'last_position_at' => $enteredAt,
            'entered_latitude' => (float) $position['lat'],
            'entered_longitude' => (float) $position['lng'],
        ]);
    }

    public function switchForeignGeofenceInterval(
        Equipment $unit,
        ?UnitForeignGeofenceInterval $openInterval,
        ?Geofence $homeGeofence,
        Geofence $foreignGeofence,
        array $position
    ): UnitForeignGeofenceInterval {
        $positionAt = $this->positionTime($position);
        $this->closeForeignGeofenceInterval($openInterval, $position, $positionAt);

        return $this->openForeignGeofenceInterval($unit, $homeGeofence, $foreignGeofence, $position, $positionAt);
    }

    public function closeForeignGeofenceInterval(
        ?UnitForeignGeofenceInterval $interval,
        ?array $position = null,
        ?Carbon $leftAt = null
    ): void {
        if (! $interval instanceof UnitForeignGeofenceInterval) {
            return;
        }

        $leftAt ??= $this->validPosition($position)
            ? $this->positionTime($position)
            : ($interval->last_position_at ?: $interval->entered_at);

        $interval->update([
            'left_at' => $leftAt,
            'duration_seconds' => (int) max(0, $interval->entered_at->diffInSeconds($leftAt)),
            'status' => UnitForeignGeofenceInterval::STATUS_CLOSED,
            'left_latitude' => $this->validPosition($position) ? (float) $position['lat'] : null,
            'left_longitude' => $this->validPosition($position) ? (float) $position['lng'] : null,
        ]);
    }

    public function getCurrentViolations(array $filters = []): Collection
    {
        return $this->currentViolationQuery($filters)
            ->orderBy('unit_id')
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $interval->unit instanceof Equipment && $this->unitCanBeMonitored($interval->unit))
            ->unique('unit_id')
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->passesDashboardFilters($interval))
            ->values();
    }

    public function currentViolationQuery(array $filters = []): Builder
    {
        $from = Carbon::parse($filters['date_from'] ?? $filters['from'] ?? now(config('app.timezone'))->startOfMonth())->startOfDay();
        $to = Carbon::parse($filters['date_to'] ?? $filters['to'] ?? now(config('app.timezone'))->toDateString())->endOfDay();
        $ownershipType = $this->ownershipType($filters);

        return UnitForeignGeofenceInterval::query()
            ->with([
                'unit:id,name,registration_number,wialon_unit_id,equipment_type_id,project_id,project_wialon_group_id,matched_wialon_group_id,matched_wialon_group_name,ownership_type,active,excluded_from_dashboard,last_position_json,last_synced_at',
                'unit.type:id,name',
                'unit.project:id,name',
                'unit.projectWialonGroup:id,wialon_group_id,name,project_id,ownership_type',
                'homeProject:id,name',
                'homeGeofence:id,name,project_id,active',
                'foreignProject:id,name',
                'foreignGeofence:id,name,project_id,active',
            ])
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->whereNotNull('home_project_id')
            ->whereColumn('home_project_id', '<>', 'foreign_project_id')
            ->where('entered_at', '<=', $to)
            ->where('last_position_at', '>=', $from)
            ->when(filled($filters['project_id'] ?? null), fn (Builder $query) => $query->where('home_project_id', (int) $filters['project_id']))
            ->when(filled($filters['current_geozone_project_id'] ?? null), fn (Builder $query) => $query->where('foreign_project_id', (int) $filters['current_geozone_project_id']))
            ->when(filled($filters['current_geozone_id'] ?? null), fn (Builder $query) => $query->where('foreign_geofence_id', (int) $filters['current_geozone_id']))
            ->whereHas('unit', function (Builder $query) use ($filters, $ownershipType): void {
                $query->where('active', true)
                    ->visibleInDashboard()
                    ->classifiedForDashboard()
                    ->whereNotNull('project_id')
                    ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE]);

                if (filled($filters['equipment_type_id'] ?? null)) {
                    $query->where('equipment_type_id', (int) $filters['equipment_type_id']);
                }

                if ($ownershipType !== null) {
                    $query->where('ownership_type', $ownershipType);
                }

                if (trim((string) ($filters['search'] ?? '')) !== '') {
                    $search = '%'.trim((string) $filters['search']).'%';
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('name', 'like', $search)
                            ->orWhere('registration_number', 'like', $search)
                            ->orWhere('wialon_unit_id', 'like', $search)
                            ->orWhereHas('project', fn (Builder $query) => $query->where('name', 'like', $search))
                            ->orWhereHas('type', fn (Builder $query) => $query->where('name', 'like', $search));
                    });
                }
            });
    }

    public function effectiveDurationSeconds(UnitForeignGeofenceInterval $interval): int
    {
        $upperBound = $interval->left_at ?: ($interval->last_position_at ?: $interval->entered_at);

        return (int) max(0, $interval->entered_at->diffInSeconds($upperBound));
    }

    public function isStale(UnitForeignGeofenceInterval $interval): bool
    {
        return $interval->last_position_at
            && $interval->last_position_at->lt(now(config('app.timezone'))->subMinutes($this->staleAfterMinutes()));
    }

    public function normalizedVehicleTypeName(?string $name): string
    {
        return FleetVehicleType::display($name);
    }

    /**
     * @return array<int, string>
     */
    public function allowedVehicleTypeNames(): array
    {
        return config('fleet.foreign_geofence.allowed_vehicle_types', [
            'Dump Truck',
            'Bulldozer',
            'Excavator',
            'Road Grader',
            'Loader',
            'Backhoe Loader',
            'Road Roller',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function normalizedAllowedVehicleTypeNames(): array
    {
        return collect($this->allowedVehicleTypeNames())
            ->map(fn (?string $name): string => $this->normalizedVehicleTypeName($name))
            ->unique()
            ->values()
            ->all();
    }

    public function unitCanBeMonitored(Equipment $unit): bool
    {
        $unit->loadMissing('type');

        return $unit->active
            && ! $unit->excluded_from_dashboard
            && $unit->project_id !== null
            && ($unit->project_wialon_group_id !== null || $unit->matched_wialon_group_id !== null)
            && in_array($unit->ownership_type, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)
            && in_array($this->normalizedVehicleTypeName($unit->type?->name), $this->normalizedAllowedVehicleTypeNames(), true)
            && $this->homeProjectGeofences($unit)->isNotEmpty();
    }

    public function intervalPassesMinimumDuration(UnitForeignGeofenceInterval $interval): bool
    {
        return $this->passesMinimumDuration($interval);
    }

    public function intervalPassesDashboardFilters(UnitForeignGeofenceInterval $interval): bool
    {
        return $this->passesDashboardFilters($interval);
    }

    public function minimumMinutes(): int
    {
        return ForeignGeofenceSettings::minimumMinutes();
    }

    public function staleAfterMinutes(): int
    {
        return max(1, (int) config('fleet.foreign_geofence.stale_after_minutes', 30));
    }

    public function includeStaleIntervals(): bool
    {
        return (bool) config('fleet.foreign_geofence.include_stale', false);
    }

    public function hasValidPosition(?array $position): bool
    {
        return $this->validPosition($position);
    }

    public function positionTimestamp(?array $position): ?Carbon
    {
        if (! is_array($position) || ! filled($position['time'] ?? null)) {
            return null;
        }

        try {
            return Carbon::parse($position['time'], config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public function positionIsFresh(?array $position): bool
    {
        $positionAt = $this->positionTimestamp($position);

        return $positionAt !== null
            && $positionAt->gte(now(config('app.timezone'))->subMinutes($this->staleAfterMinutes()));
    }

    /**
     * @return Collection<int, Geofence>
     */
    public function homeProjectGeofences(Equipment $unit): Collection
    {
        $project = $this->resolveHomeProject($unit);

        if (! $project instanceof Project) {
            return collect();
        }

        return $this->resolveAllowedHomeGeofences($project);
    }

    /**
     * @return Collection<int, UnitForeignGeofenceInterval>
     */
    private function openIntervals(Equipment $unit): Collection
    {
        return UnitForeignGeofenceInterval::query()
            ->where('unit_id', $unit->id)
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  iterable<UnitForeignGeofenceInterval>  $intervals
     */
    private function closeForeignGeofenceIntervals(iterable $intervals, ?array $position = null, ?Carbon $leftAt = null): void
    {
        foreach ($intervals as $interval) {
            $this->closeForeignGeofenceInterval($interval, $position, $leftAt);
        }
    }

    /**
     * @param  Collection<int, Geofence>  $geofences
     */
    private function chooseSmallestGeofence(Collection $geofences): ?Geofence
    {
        return $geofences
            ->sortBy([
                fn (Geofence $a, Geofence $b): int => $this->geofenceArea($a) <=> $this->geofenceArea($b),
                fn (Geofence $a, Geofence $b): int => $a->id <=> $b->id,
            ])
            ->first();
    }

    private function geofenceArea(Geofence $geofence): float
    {
        $geometry = $geofence->geometry_json;
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates)) {
            return PHP_FLOAT_MAX;
        }

        if ($type === 'Polygon') {
            return $this->polygonArea($coordinates);
        }

        if ($type === 'MultiPolygon') {
            return collect($coordinates)
                ->filter(fn ($polygon): bool => is_array($polygon))
                ->sum(fn (array $polygon): float => $this->polygonArea($polygon));
        }

        return PHP_FLOAT_MAX;
    }

    private function polygonArea(array $polygon): float
    {
        $outerRing = $polygon[0] ?? [];

        if (! is_array($outerRing) || count($outerRing) < 3) {
            return PHP_FLOAT_MAX;
        }

        return abs($this->ringArea($outerRing));
    }

    private function ringArea(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $area += ((float) ($ring[$j][0] ?? 0) * (float) ($ring[$i][1] ?? 0))
                - ((float) ($ring[$i][0] ?? 0) * (float) ($ring[$j][1] ?? 0));
        }

        return $area / 2;
    }

    private function normalizedName(?string $value): string
    {
        $key = (string) $value;

        if (array_key_exists($key, $this->normalizedNames)) {
            return $this->normalizedNames[$key];
        }

        return $this->normalizedNames[$key] = (string) Str::of($key)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /**
     * Active geofences are request-scoped reference data. Loading them
     * once removes the dashboard N+1 caused by per-interval home zone checks.
     *
     * @return Collection<int, Geofence>
     */
    private function activeGeofences(): Collection
    {
        if ($this->activeGeofences instanceof Collection) {
            return $this->activeGeofences;
        }

        return $this->activeGeofences = Geofence::query()
            ->with('project:id,name')
            ->where('active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'normalized_name', 'project_id', 'geometry_json', 'active']);
    }

    /**
     * @param  array{lat?: mixed, lng?: mixed}  $position
     */
    private function containsPosition(Geofence $geofence, array $position): bool
    {
        $geometry = $geofence->geometry_json;
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates)) {
            return false;
        }

        $lng = (float) $position['lng'];
        $lat = (float) $position['lat'];

        if ($type === 'Polygon') {
            return $this->pointInPolygon($lng, $lat, $coordinates);
        }

        if ($type === 'MultiPolygon') {
            foreach ($coordinates as $polygon) {
                if (is_array($polygon) && $this->pointInPolygon($lng, $lat, $polygon)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pointInPolygon(float $lng, float $lat, array $polygon): bool
    {
        $outerRing = $polygon[0] ?? [];

        if (! is_array($outerRing) || count($outerRing) < 3 || ! $this->pointInRing($lng, $lat, $outerRing)) {
            return false;
        }

        foreach (array_slice($polygon, 1) as $hole) {
            if (is_array($hole) && $this->pointInRing($lng, $lat, $hole)) {
                return false;
            }
        }

        return true;
    }

    private function pointInRing(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($ring[$i][0] ?? 0);
            $yi = (float) ($ring[$i][1] ?? 0);
            $xj = (float) ($ring[$j][0] ?? 0);
            $yj = (float) ($ring[$j][1] ?? 0);

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1.0)) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function validPosition(?array $position): bool
    {
        return is_array($position)
            && isset($position['lat'], $position['lng'])
            && is_numeric($position['lat'])
            && is_numeric($position['lng'])
            && $this->positionTimestamp($position) !== null;
    }

    private function positionTime(array $position): Carbon
    {
        return $this->positionTimestamp($position) ?? Carbon::parse($position['time'], config('app.timezone'));
    }

    private function passesMinimumDuration(UnitForeignGeofenceInterval $interval): bool
    {
        if ((bool) config('fleet.foreign_geofence.show_all', false)) {
            return true;
        }

        return $this->effectiveDurationSeconds($interval) >= ($this->minimumMinutes() * 60);
    }

    private function passesDashboardFilters(UnitForeignGeofenceInterval $interval): bool
    {
        if (! $this->includeStaleIntervals() && $this->isStale($interval)) {
            return false;
        }

        return $this->passesMinimumDuration($interval);
    }

    private function ownershipType(array $filters): ?string
    {
        if (in_array($filters['ownership_type'] ?? null, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            return $filters['ownership_type'];
        }

        return match (mb_strtolower(trim((string) ($filters['ownership'] ?? '')))) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => null,
        };
    }

    /**
     * @return array{
     *     can_monitor: bool,
     *     is_foreign_project_geofence: bool,
     *     reason: string,
     *     position_at: ?Carbon,
     *     has_valid_position: bool,
     *     has_fresh_position: bool,
     *     home_geofence: ?Geofence,
     *     home_geofences: Collection<int, Geofence>,
     *     current_geofence: ?Geofence,
     *     selected_current_geofence: ?Geofence,
     *     current_geofences: Collection<int, Geofence>
     * }
     */
    private function analysis(
        bool $canMonitor,
        bool $isForeignProjectGeofence,
        string $reason,
        ?Carbon $positionAt,
        bool $hasValidPosition,
        bool $hasFreshPosition,
        ?Geofence $homeGeofence = null,
        ?Geofence $currentGeofence = null,
        ?Collection $homeGeofences = null,
        ?Collection $currentGeofences = null,
        ?Geofence $selectedCurrentGeofence = null
    ): array {
        return [
            'can_monitor' => $canMonitor,
            'is_foreign_project_geofence' => $isForeignProjectGeofence,
            'reason' => $reason,
            'position_at' => $positionAt,
            'has_valid_position' => $hasValidPosition,
            'has_fresh_position' => $hasFreshPosition,
            'home_geofence' => $homeGeofence,
            'home_geofences' => $homeGeofences ?? collect(),
            'current_geofence' => $currentGeofence,
            'selected_current_geofence' => $selectedCurrentGeofence ?? $currentGeofence,
            'current_geofences' => $currentGeofences ?? collect(),
        ];
    }

    private function invalidPositionReason(?array $position): string
    {
        if (! is_array($position) || ! isset($position['lat'], $position['lng']) || ! is_numeric($position['lat']) || ! is_numeric($position['lng'])) {
            return 'missing_position';
        }

        return 'invalid_position_time';
    }

    private function unitExclusionReason(Equipment $unit): ?string
    {
        if (! $unit->active) {
            return 'inactive-unit';
        }

        if ($unit->excluded_from_dashboard) {
            return 'excluded-from-dashboard';
        }

        if ($unit->project_id === null) {
            return 'missing_home_project';
        }

        if ($unit->project_wialon_group_id === null && $unit->matched_wialon_group_id === null) {
            return 'missing_home_project';
        }

        if (! in_array($unit->ownership_type, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            return 'unsupported-ownership';
        }

        if (! in_array($this->normalizedVehicleTypeName($unit->type?->name), $this->normalizedAllowedVehicleTypeNames(), true)) {
            return 'vehicle_type_not_allowed';
        }

        return null;
    }
}
