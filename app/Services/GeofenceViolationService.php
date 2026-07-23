<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GeofenceViolationService
{
    public function __construct(
        private ForeignProjectGeofenceMonitoringService $monitoring,
    ) {}

    /**
     * @return array{labels: array<int, string>, counts: array<int, int>, project_ids: array<int, int|null>, geofence_ids: array<int, int|null>, total: int, rows: array<int, array<string, mixed>>}
     */
    public function summary(array $filters): array
    {
        $rows = $this->intervals($filters)
            ->groupBy(fn (UnitForeignGeofenceInterval $interval): string => $this->sectorKey($interval))
            ->map(function (Collection $intervals): array {
                $interval = $intervals->first();

                return [
                    'project_id' => $interval->foreign_project_id ? (int) $interval->foreign_project_id : null,
                    'geofence_id' => $interval->foreign_geofence_id ? (int) $interval->foreign_geofence_id : null,
                    'project' => $interval->foreignProject?->name ?: ($interval->foreign_project_name ?: '-'),
                    'geofence' => $interval->foreignGeofence?->name ?: ($interval->foreign_geofence_name ?: '-'),
                    'label' => $this->currentGeozoneLabel($interval),
                    'sector_key' => $this->sectorKey($interval),
                    'count' => $intervals->unique(fn (UnitForeignGeofenceInterval $interval): string => $this->unitKey($interval))->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        return [
            'labels' => $rows->pluck('label')->values()->all(),
            'counts' => $rows->pluck('count')->map(fn ($value): int => (int) $value)->values()->all(),
            'project_ids' => $rows->pluck('project_id')->values()->all(),
            'geofence_ids' => $rows->pluck('geofence_id')->values()->all(),
            'sector_keys' => $rows->pluck('sector_key')->values()->all(),
            'total' => (int) $rows->sum('count'),
            'rows' => $rows->all(),
        ];
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $items = $this->intervals($filters)
            ->sortByDesc(fn (UnitForeignGeofenceInterval $interval): int => $this->monitoring->effectiveDurationSeconds($interval))
            ->values();

        return new Paginator(
            $items->forPage($page, $perPage)
                ->values()
                ->map(fn (UnitForeignGeofenceInterval $interval): array => $this->row($interval))
                ->all(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function exportRows(array $filters): array
    {
        return $this->intervals($filters)
            ->sortByDesc(fn (UnitForeignGeofenceInterval $interval): int => $this->monitoring->effectiveDurationSeconds($interval))
            ->values()
            ->map(fn (UnitForeignGeofenceInterval $interval, int $index): array => array_values($this->row($interval, $index + 1)))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'number' => '№',
            'equipment' => 'Texnika',
            'registration_number' => 'Qeydiyyat nişanı',
            'vehicle_type' => 'Texnika növü',
            'ownership' => 'Ownership',
            'wialon_group' => 'Wialon group',
            'home_project' => 'Ev layihəsi',
            'home_geofence' => 'Ev geozonası',
            'current_project' => 'Cari layihə',
            'current_geofence' => 'Cari geozona',
            'entered_at' => 'Geozonaya giriş vaxtı',
            'left_at' => 'Geozonadan çıxış vaxtı',
            'duration' => 'Geozonada qalma müddəti',
            'reported_project' => 'Reported project',
            'project_mismatch' => 'Project mismatch',
            'wialon_id' => 'Wialon ID',
        ];
    }

    /**
     * @return Collection<int, UnitForeignGeofenceInterval>
     */
    public function currentIntervals(array $filters = [], bool $applyMinimumDuration = true): Collection
    {
        $intervals = $this->baseIntervals($filters);

        if (! $applyMinimumDuration) {
            return $intervals;
        }

        return $intervals
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->monitoring->intervalPassesDashboardFilters($interval))
            ->values();
    }

    /**
     * Base current selection used by Dashboard, modal, Excel and diagnostics.
     *
     * The current-state widget reads open position-monitoring intervals only.
     * Closed Wialon report intervals remain historical records and are not
     * mixed into this dataset.
     *
     * @return Collection<int, UnitForeignGeofenceInterval>
     */
    public function baseIntervals(array $filters = []): Collection
    {
        return $this->currentIntervalQuery($filters)
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->intervalIsEligible($interval))
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => ! filled($filters['current_geozone_key'] ?? null) || $this->sectorKey($interval) === (string) $filters['current_geozone_key'])
            ->unique(fn (UnitForeignGeofenceInterval $interval): string => $this->unitKey($interval))
            ->sortByDesc(fn (UnitForeignGeofenceInterval $interval): int => $this->monitoring->effectiveDurationSeconds($interval))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(array $filters = []): array
    {
        $units = Equipment::query()
            ->with(['type:id,name', 'project:id,name', 'projectWialonGroup:id,wialon_group_id,name,project_id,ownership_type'])
            ->when(filled($filters['unit'] ?? null), function ($query) use ($filters): void {
                $unit = trim((string) $filters['unit']);
                $query->where(function ($query) use ($unit): void {
                    $query->where('id', $unit)
                        ->orWhere('wialon_unit_id', $unit);
                });
            })
            ->orderBy('name')
            ->get();
        $allowedTypeUnits = $units
            ->filter(fn (Equipment $unit): bool => in_array(
                $this->monitoring->normalizedVehicleTypeName($unit->type?->name),
                $this->monitoring->normalizedAllowedVehicleTypeNames(),
                true
            ))
            ->values();
        $baseIntervals = $this->baseIntervals($filters);
        $dashboardIntervals = $this->currentIntervals($filters);
        $summary = $this->summary($filters);

        return [
            'total_units' => $units->count(),
            'allowed_type_units' => $allowedTypeUnits->count(),
            'units_with_project' => $allowedTypeUnits->filter(fn (Equipment $unit): bool => $unit->project_id !== null)->count(),
            'projects_with_geofence' => Geofence::query()
                ->where('active', true)
                ->whereNotNull('project_id')
                ->distinct('project_id')
                ->count('project_id'),
            'projects_without_geofence' => Project::query()
                ->where('active', true)
                ->whereDoesntHave('geofences', fn ($query) => $query->where('active', true))
                ->count(),
            'groups_without_project' => ProjectWialonGroup::query()
                ->whereDoesntHave('project')
                ->count(),
            'units_with_fresh_position' => $allowedTypeUnits
                ->filter(fn (Equipment $unit): bool => $this->monitoring->positionIsFresh($unit->last_position_json))
                ->count(),
            'units_with_invalid_position' => $allowedTypeUnits
                ->filter(fn (Equipment $unit): bool => ! $this->monitoring->hasValidPosition($unit->last_position_json))
                ->count(),
            'units_with_stale_position' => $allowedTypeUnits
                ->filter(fn (Equipment $unit): bool => $this->monitoring->hasValidPosition($unit->last_position_json) && ! $this->monitoring->positionIsFresh($unit->last_position_json))
                ->count(),
            'open_intervals' => UnitForeignGeofenceInterval::query()
                ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
                ->count(),
            'intervals_below_minimum' => $baseIntervals
                ->reject(fn (UnitForeignGeofenceInterval $interval): bool => $this->intervalPassesMinimumDuration($interval))
                ->count(),
            'intervals_at_or_above_minimum' => $baseIntervals
                ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->intervalPassesMinimumDuration($interval))
                ->count(),
            'stale_intervals' => $baseIntervals
                ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->monitoring->isStale($interval))
                ->count(),
            'dashboard_total' => $dashboardIntervals->count(),
            'summary_total' => (int) ($summary['total'] ?? 0),
            'groups' => $summary['rows'] ?? [],
            'consistency' => [
                'diagnose_total' => $dashboardIntervals->count(),
                'donut_center' => (int) ($summary['total'] ?? 0),
                'table_sum' => collect($summary['rows'] ?? [])->sum(fn (array $row): int => (int) ($row['count'] ?? 0)),
                'modal_total' => $this->paginate([...$filters, 'page' => 1, 'per_page' => 100])->total(),
                'excel_rows' => count($this->exportRows($filters)),
            ],
            'details' => $this->diagnosticDetails($units, $baseIntervals, $dashboardIntervals),
        ];
    }

    /**
     * @return Collection<int, UnitForeignGeofenceInterval>
     */
    private function intervals(array $filters): Collection
    {
        return $this->currentIntervals($filters);
    }

    private function currentIntervalQuery(array $filters): Builder
    {
        $from = Carbon::parse($filters['date_from'] ?? $filters['from'] ?? now(config('app.timezone'))->startOfDay(), config('app.timezone'))->startOfDay();
        $to = Carbon::parse($filters['date_to'] ?? $filters['to'] ?? now(config('app.timezone'))->endOfDay(), config('app.timezone'))->endOfDay();
        $ownershipType = $filters['ownership_type'] ?? null;

        if (($filters['ownership'] ?? 'all') !== 'all' && ! $ownershipType) {
            $ownershipType = ($filters['ownership'] ?? null) === 'icare' ? Equipment::OWNERSHIP_ICARE : Equipment::OWNERSHIP_NWC;
        }

        return UnitForeignGeofenceInterval::query()
            ->with([
                'unit.type:id,name',
                'unit.project:id,name',
                'unit.projectWialonGroup:id,wialon_group_id,name,project_id,ownership_type',
                'homeProject:id,name',
                'homeGeofence:id,name,project_id,active',
                'foreignProject:id,name',
                'foreignGeofence:id,name,project_id,active',
            ])
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->where('entered_at', '<=', $to)
            ->where('last_position_at', '>=', $from)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('home_project_id', $projectId))
            ->when($ownershipType, fn (Builder $query, string $ownershipType) => $query->whereHas(
                'unit',
                fn (Builder $query) => $query->where('ownership_type', $ownershipType)
            ))
            ->when($filters['current_geozone_project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('foreign_project_id', $projectId))
            ->when($filters['current_geozone_id'] ?? null, fn (Builder $query, int $geofenceId) => $query->where('foreign_geofence_id', $geofenceId))
            ->when(filled($filters['unit'] ?? null), function (Builder $query) use ($filters): void {
                $unit = trim((string) $filters['unit']);
                $query->where(function (Builder $query) use ($unit): void {
                    $query->where('wialon_unit_id', $unit)
                        ->orWhere('unit_id', $unit);
                });
            })
            ->when($filters['equipment_type_id'] ?? null, function (Builder $query, int $typeId): void {
                $query->whereHas('unit', fn (Builder $query) => $query->where('equipment_type_id', $typeId));
            })
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = '%'.trim((string) $filters['search']).'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('foreign_geofence_name', 'like', $search)
                        ->orWhere('foreign_project_name', 'like', $search)
                        ->orWhere('home_project_name', 'like', $search)
                        ->orWhere('wialon_unit_id', 'like', $search)
                        ->orWhereHas('unit', function (Builder $query) use ($search): void {
                            $query->where('name', 'like', $search)
                                ->orWhere('registration_number', 'like', $search);
                        });
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(UnitForeignGeofenceInterval $interval, ?int $number = null): array
    {
        $unit = $interval->unit;
        $durationSeconds = $this->monitoring->effectiveDurationSeconds($interval);

        return [
            'number' => $number,
            'equipment' => $unit?->name ?? '',
            'registration_number' => $unit?->registration_number ?: '-',
            'vehicle_type' => $this->monitoring->normalizedVehicleTypeName($unit?->type?->name),
            'ownership' => $interval->ownership_type ?: ($unit?->ownership_type ?? ''),
            'wialon_group' => $interval->source_group_name ?: ($unit?->projectWialonGroup?->name ?? ''),
            'home_project' => $interval->homeProject?->name ?? $interval->home_project_name ?? $unit?->project?->name ?? '',
            'home_geofence' => implode(', ', $interval->home_geofence_names_json ?? array_filter([$interval->homeGeofence?->name])),
            'current_project' => $interval->foreignProject?->name ?? $interval->foreign_project_name ?? '',
            'current_geofence' => $interval->foreignGeofence?->name ?? $interval->foreign_geofence_name ?? '',
            'entered_at' => $this->formatDateTime($interval->entered_at),
            'left_at' => $this->formatDateTime($interval->left_at),
            'duration' => $this->durationLabel($durationSeconds),
            'reported_project' => $interval->reported_project ?? '',
            'project_mismatch' => $interval->project_mismatch ? 'yes' : 'no',
            'wialon_id' => $interval->wialon_unit_id ?: ($unit?->wialon_unit_id ?? ''),
        ];
    }

    private function intervalPassesMinimumDuration(UnitForeignGeofenceInterval $interval): bool
    {
        if ((bool) config('fleet.foreign_geofence.show_all', false)) {
            return true;
        }

        return $this->monitoring->intervalPassesMinimumDuration($interval);
    }

    private function unitKey(UnitForeignGeofenceInterval $interval): string
    {
        return (string) ($interval->wialon_unit_id ?: $interval->unit_id ?: $interval->id);
    }

    private function sectorKey(UnitForeignGeofenceInterval $interval): string
    {
        if ($interval->foreign_project_id || $interval->foreign_geofence_id) {
            return (string) ($interval->foreign_project_id ?? 'null').':'.(string) ($interval->foreign_geofence_id ?? 'null');
        }

        return 'name:'.mb_strtolower(trim((string) $interval->foreign_geofence_name));
    }

    private function intervalIsEligible(UnitForeignGeofenceInterval $interval): bool
    {
        $unit = $interval->unit;

        if (! $unit instanceof Equipment || ! $this->monitoring->unitCanBeMonitored($unit)) {
            return false;
        }

        if ((int) $interval->home_project_id !== (int) $unit->project_id) {
            return false;
        }

        if ($interval->foreign_project_id !== null && (int) $interval->home_project_id === (int) $interval->foreign_project_id) {
            return false;
        }

        if ($interval->match_status === 'ambiguous') {
            return false;
        }

        if ($interval->foreign_geofence_id !== null && $this->monitoring->homeProjectGeofences($unit)->contains('id', $interval->foreign_geofence_id)) {
            return false;
        }

        if ($interval->foreignGeofence && ! $interval->foreignGeofence->active) {
            return false;
        }

        return true;
    }

    private function currentGeozoneLabel(UnitForeignGeofenceInterval $interval): string
    {
        $project = trim((string) ($interval->foreignProject?->name ?? $interval->foreign_project_name ?? ''));
        $geofence = trim((string) ($interval->foreignGeofence?->name ?? $interval->foreign_geofence_name ?? ''));

        if ($project === '') {
            return $geofence;
        }

        if ($geofence === '' || $geofence === $project) {
            return $project;
        }

        return $project.' / '.$geofence;
    }

    /**
     * @param  Collection<int, Equipment>  $units
     * @param  Collection<int, UnitForeignGeofenceInterval>  $baseIntervals
     * @param  Collection<int, UnitForeignGeofenceInterval>  $dashboardIntervals
     * @return array<int, array<string, mixed>>
     */
    private function diagnosticDetails(Collection $units, Collection $baseIntervals, Collection $dashboardIntervals): array
    {
        $openIntervalsByUnit = UnitForeignGeofenceInterval::query()
            ->with([
                'homeGeofence:id,name,project_id,active',
                'foreignProject:id,name',
                'foreignGeofence:id,name,project_id,active',
            ])
            ->whereIn('unit_id', $units->pluck('id')->all())
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('unit_id');
        $baseByUnit = $baseIntervals->keyBy('unit_id');
        $dashboardByUnit = $dashboardIntervals->keyBy('unit_id');

        return $units
            ->map(function (Equipment $unit) use ($openIntervalsByUnit, $baseByUnit, $dashboardByUnit): array {
                $analysis = $this->monitoring->analyzeUnitPosition($unit);
                $openIntervals = $openIntervalsByUnit->get($unit->id, collect());
                $baseInterval = $baseByUnit->get($unit->id);
                $dashboardInterval = $dashboardByUnit->get($unit->id);
                $selectedInterval = $dashboardInterval ?? $baseInterval ?? $openIntervals->first();
                $reason = $analysis['reason'];
                $included = $dashboardInterval instanceof UnitForeignGeofenceInterval;

                if ($included) {
                    $reason = 'included_dashboard';
                } elseif ($baseInterval instanceof UnitForeignGeofenceInterval) {
                    $reason = $this->monitoring->isStale($baseInterval)
                        ? 'stale_position'
                        : 'foreign_interval_under_threshold';
                } elseif ($openIntervals->count() > 1) {
                    $reason = 'multiple_geofence_conflict';
                } elseif ($openIntervals->isNotEmpty()) {
                    $reason = 'open-interval-not-eligible';
                } elseif (($analysis['reason'] ?? '') === 'inside_foreign_project_geofence' && ! ($analysis['has_fresh_position'] ?? false)) {
                    $reason = 'stale_position';
                }

                $groupIds = collect([
                    $unit->projectWialonGroup?->wialon_group_id,
                    $unit->matched_wialon_group_id,
                ])->filter()->unique()->values()->implode(', ');
                $allowedHomeGeofences = collect($analysis['home_geofences'] ?? [])
                    ->map(fn (Geofence $geofence): string => $this->geofenceLabel($geofence))
                    ->implode(', ');
                $currentContainingGeofences = collect($analysis['current_geofences'] ?? [])
                    ->map(fn (Geofence $geofence): string => $this->geofenceLabel($geofence))
                    ->implode(', ');
                $selectedCurrentGeofence = $selectedInterval?->foreignGeofence
                    ?? $analysis['selected_current_geofence']
                    ?? null;
                $foreignProject = $selectedInterval?->foreignProject?->name
                    ?? $selectedCurrentGeofence?->project?->name
                    ?? '';

                return [
                    'unit' => $unit->name,
                    'wialon_id' => $unit->wialon_unit_id,
                    'group_ids' => $groupIds,
                    'type' => $this->monitoring->normalizedVehicleTypeName($unit->type?->name),
                    'home_project' => $unit->project?->name ?? '',
                    'home_geofence' => $selectedInterval?->homeGeofence?->name
                        ?? $analysis['home_geofence']?->name
                        ?? '',
                    'allowed_home_geofences' => $allowedHomeGeofences,
                    'open_intervals' => $openIntervals->count(),
                    'current_containing_geofences' => $currentContainingGeofences,
                    'selected_current_geofence' => $selectedCurrentGeofence instanceof Geofence ? $this->geofenceLabel($selectedCurrentGeofence) : '',
                    'foreign_project' => $foreignProject,
                    'current_geofence' => $selectedInterval?->foreignGeofence?->name
                        ?? $selectedCurrentGeofence?->name
                        ?? '',
                    'entered_at' => $this->formatDateTime($dashboardInterval?->entered_at ?? $baseInterval?->entered_at),
                    'duration' => $dashboardInterval instanceof UnitForeignGeofenceInterval || $baseInterval instanceof UnitForeignGeofenceInterval
                        ? $this->durationLabel($this->monitoring->effectiveDurationSeconds($dashboardInterval ?? $baseInterval), $this->monitoring->isStale($dashboardInterval ?? $baseInterval))
                        : '',
                    'last_position_at' => $this->formatDateTime($dashboardInterval?->last_position_at ?? $baseInterval?->last_position_at ?? $analysis['position_at']),
                    'stale' => $selectedInterval instanceof UnitForeignGeofenceInterval
                        ? ($this->monitoring->isStale($selectedInterval) ? 'yes' : 'no')
                        : ($analysis['has_valid_position'] ? ($analysis['has_fresh_position'] ? 'no' : 'yes') : 'invalid'),
                    'included' => $included ? 'yes' : 'no',
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();
    }

    private function geofenceLabel(Geofence $geofence): string
    {
        $project = trim((string) ($geofence->project?->name ?? ''));
        $name = trim((string) $geofence->name);

        if ($project === '') {
            return $name;
        }

        if ($name === '' || $name === $project) {
            return $project;
        }

        return $project.' / '.$name;
    }

    private function formatDateTime(?Carbon $date): string
    {
        return $date?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '';
    }

    private function durationLabel(int $seconds, bool $stale = false): string
    {
        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $label = trim($hours.' saat '.$remainingMinutes.' dəqiqə');

        return $stale ? $label.' (stale)' : $label;
    }

    public function exportFilename(array $filters): string
    {
        $from = Carbon::parse($filters['date_from'] ?? $filters['from'] ?? now(config('app.timezone')), config('app.timezone'))->toDateString();
        $to = Carbon::parse($filters['date_to'] ?? $filters['to'] ?? now(config('app.timezone')), config('app.timezone'))->toDateString();
        $parts = ['geozonadan_cixma_hallari'];

        if (filled($filters['project_id'] ?? null)) {
            $project = Project::query()->whereKey((int) $filters['project_id'])->value('name');
            $parts[] = Str::slug($project ?: 'project-'.$filters['project_id'], '_');
        } else {
            $parts[] = 'butun_layiheler';
        }

        if (filled($filters['current_geozone_id'] ?? null) || filled($filters['current_geozone_project_id'] ?? null)) {
            $interval = $this->intervals($filters)->first();
            $project = $interval instanceof UnitForeignGeofenceInterval
                ? $this->currentGeozoneLabel($interval)
                : 'foreign-project-geofence';

            $parts[] = Str::slug($project, '_');
        }

        $parts[] = $from;
        $parts[] = $to;

        return implode('_', array_filter($parts)).'.xlsx';
    }
}
