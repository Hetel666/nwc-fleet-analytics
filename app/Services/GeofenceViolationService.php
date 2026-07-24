<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use App\Support\FleetVehicleType;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeofenceViolationService
{
    /**
     * @var array<int, string>
     */
    private const HIDDEN_EXPORT_COLUMNS = [
        'registration_number',
        'wialon_group',
        'home_geofence',
        'wialon_id',
    ];

    public function __construct(
        private ForeignProjectGeofenceMonitoringService $monitoring,
    ) {
    }

    /**
     * @return array{labels: array<int, string>, counts: array<int, int>, project_ids: array<int, int|null>, geofence_ids: array<int, int|null>, total: int, rows: array<int, array<string, mixed>>}
     */
    public function summary(array $filters): array
    {
        $rows = $this->eligibleIntervalRecords($filters)
            ->when(filled($filters['current_geozone_key'] ?? null), fn (Collection $rows): Collection => $rows
                ->filter(fn (object $row): bool => $this->recordSectorKey($row) === (string) $filters['current_geozone_key']))
            ->groupBy(fn (object $row): string => $this->recordSectorKey($row))
            ->map(function (Collection $records): array {
                $record = $records->first();

                return [
                    'project_id' => $record->foreign_project_id ? (int) $record->foreign_project_id : null,
                    'geofence_id' => $record->foreign_geofence_id ? (int) $record->foreign_geofence_id : null,
                    'project' => $record->foreign_project_name ?: '-',
                    'geofence' => $record->foreign_geofence_name ?: '-',
                    'label' => $this->recordCurrentGeozoneLabel($record),
                    'sector_key' => $this->recordSectorKey($record),
                    'count' => $records->unique(fn (object $record): string => $this->recordUnitKey($record))->count(),
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function visibleExportRow(array $row): array
    {
        return collect($row)
            ->except(self::HIDDEN_EXPORT_COLUMNS)
            ->all();
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $ids = $this->detailIntervalIds($filters);
        $pageIds = $ids->forPage($page, $perPage)->values();
        $intervals = $this->loadIntervalsByIds($pageIds->all());

        return new Paginator(
            $pageIds
                ->map(fn (int $id): ?UnitForeignGeofenceInterval => $intervals->get($id))
                ->filter()
                ->map(fn (UnitForeignGeofenceInterval $interval): array => $this->row($interval))
                ->all(),
            $ids->count(),
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
        $ids = $this->detailIntervalIds($filters);
        $intervals = $this->loadIntervalsByIds($ids->all());

        return $ids
            ->map(fn (int $id): ?UnitForeignGeofenceInterval => $intervals->get($id))
            ->filter()
            ->map(fn (UnitForeignGeofenceInterval $interval, int $index): array => array_values($this->visibleExportRow($this->row($interval, $index + 1))))
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
            'vehicle_type' => 'Texnika növü',
            'ownership' => 'Ownership',
            'home_project' => 'Ev layihəsi',
            'current_project' => 'Cari layihə',
            'current_geofence' => 'Cari geozona',
            'entered_at' => 'Geozonaya giriş vaxtı',
            'left_at' => 'Geozonadan çıxış vaxtı',
            'duration' => 'Geozonada qalma müddəti',
            'reported_project' => 'Reported project',
            'project_mismatch' => 'Project mismatch',
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
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->intervalPassesMinimumDuration($interval))
            ->values();
    }

    /**
     * Base current selection used by Dashboard, modal, Excel and diagnostics.
     *
     * @return Collection<int, UnitForeignGeofenceInterval>
     */
    public function baseIntervals(array $filters = []): Collection
    {
        $openIntervals = $this->monitoring->currentViolationQuery($filters)
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get();
        $reportIntervals = $this->reportIntervalQuery($filters)
            ->orderByDesc('duration_seconds')
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get();

        return $openIntervals
            ->concat($reportIntervals)
            ->sortByDesc(fn (UnitForeignGeofenceInterval $interval): int => (int) ($interval->duration_seconds ?: $this->monitoring->effectiveDurationSeconds($interval)))
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => $this->intervalIsEligible($interval))
            ->filter(fn (UnitForeignGeofenceInterval $interval): bool => ! filled($filters['current_geozone_key'] ?? null) || $this->sectorKey($interval) === (string) $filters['current_geozone_key'])
            ->unique(fn (UnitForeignGeofenceInterval $interval): string => $this->unitKey($interval).'|'.$this->sectorKey($interval))
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

    /**
     * SQL-level base selection for Dashboard summary, modal and Excel IDs.
     * It mirrors intervalIsEligible()/intervalPassesMinimumDuration() but avoids
     * hydrating every candidate interval with all relations.
     *
     * @return Collection<int, object>
     */
    private function eligibleIntervalRecords(array $filters): Collection
    {
        $open = $this->eligibleIntervalQuery($filters, UnitForeignGeofenceInterval::STATUS_OPEN, false);
        $report = $this->eligibleIntervalQuery($filters, UnitForeignGeofenceInterval::STATUS_CLOSED, true);

        return DB::query()
            ->fromSub($open->unionAll($report), 'eligible_intervals')
            ->get();
    }

    /**
     * @return Collection<int, int>
     */
    private function detailIntervalIds(array $filters): Collection
    {
        return $this->eligibleIntervalRecords($filters)
            ->when(filled($filters['current_geozone_key'] ?? null), fn (Collection $rows): Collection => $rows
                ->filter(fn (object $row): bool => $this->recordSectorKey($row) === (string) $filters['current_geozone_key']))
            ->sortByDesc(fn (object $row): int => (int) $row->sort_duration)
            ->unique(fn (object $row): string => $this->recordUnitKey($row).'|'.$this->recordSectorKey($row))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, UnitForeignGeofenceInterval>
     */
    private function loadIntervalsByIds(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
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
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    private function eligibleIntervalQuery(array $filters, string $status, bool $reportSource): QueryBuilder
    {
        $from = Carbon::parse($filters['date_from'] ?? $filters['from'] ?? now(config('app.timezone'))->startOfDay(), config('app.timezone'))->startOfDay();
        $to = Carbon::parse($filters['date_to'] ?? $filters['to'] ?? now(config('app.timezone'))->endOfDay(), config('app.timezone'))->endOfDay();
        $ownershipType = $filters['ownership_type'] ?? null;

        if (($filters['ownership'] ?? 'all') !== 'all' && ! $ownershipType) {
            $ownershipType = ($filters['ownership'] ?? null) === 'icare' ? Equipment::OWNERSHIP_ICARE : Equipment::OWNERSHIP_NWC;
        }

        $durationExpression = $this->durationSqlExpression();
        $query = DB::table('unit_foreign_geofence_intervals as intervals')
            ->join('equipments as units', 'units.id', '=', 'intervals.unit_id')
            ->leftJoin('equipment_types as types', 'types.id', '=', 'units.equipment_type_id')
            ->leftJoin('projects as foreign_projects', 'foreign_projects.id', '=', 'intervals.foreign_project_id')
            ->leftJoin('geofences as foreign_geofences', 'foreign_geofences.id', '=', 'intervals.foreign_geofence_id')
            ->where('intervals.status', $status)
            ->whereNotNull('intervals.home_project_id')
            ->whereColumn('intervals.home_project_id', 'units.project_id')
            ->where('units.active', true)
            ->where(function (QueryBuilder $query): void {
                $query->where('units.excluded_from_dashboard', false)
                    ->orWhereNull('units.excluded_from_dashboard');
            })
            ->where(function (QueryBuilder $query): void {
                $query->whereNotNull('units.project_wialon_group_id')
                    ->orWhereNotNull('units.matched_wialon_group_id');
            })
            ->whereNotNull('units.project_id')
            ->whereIn('units.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->whereIn(DB::raw("lower(replace(replace(types.name, '-', '_'), ' ', '_'))"), $this->allowedVehicleTypeSqlKeys())
            ->whereNotNull('intervals.foreign_geofence_id')
            ->where('foreign_geofences.active', true)
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('intervals.match_status')
                    ->orWhere('intervals.match_status', '<>', 'ambiguous');
            })
            ->where('intervals.entered_at', '<=', $to)
            ->where(function (QueryBuilder $query) use ($from): void {
                $query->where('intervals.left_at', '>=', $from)
                    ->orWhereNull('intervals.left_at');
            })
            ->when($reportSource, fn (QueryBuilder $query) => $query->where('intervals.source', GeofenceReportViolationCalculator::SOURCE))
            ->when(! $reportSource, fn (QueryBuilder $query) => $query
                ->whereColumn('intervals.home_project_id', '<>', 'intervals.foreign_project_id')
                ->where('intervals.last_position_at', '>=', $from))
            ->when($filters['project_id'] ?? null, fn (QueryBuilder $query, int $projectId) => $query->where('intervals.home_project_id', $projectId))
            ->when($ownershipType, fn (QueryBuilder $query, string $ownershipType) => $query->where('intervals.ownership_type', $ownershipType))
            ->when($filters['current_geozone_project_id'] ?? null, fn (QueryBuilder $query, int $projectId) => $query->where('intervals.foreign_project_id', $projectId))
            ->when($filters['current_geozone_id'] ?? null, fn (QueryBuilder $query, int $geofenceId) => $query->where('intervals.foreign_geofence_id', $geofenceId))
            ->when($filters['equipment_type_id'] ?? null, fn (QueryBuilder $query, int $typeId) => $query->where('units.equipment_type_id', $typeId))
            ->when(filled($filters['unit'] ?? null), function (QueryBuilder $query) use ($filters): void {
                $unit = trim((string) $filters['unit']);
                $query->where(function (QueryBuilder $query) use ($unit): void {
                    $query->where('intervals.wialon_unit_id', $unit)
                        ->orWhere('intervals.unit_id', $unit);
                });
            })
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (QueryBuilder $query) use ($filters): void {
                $search = '%'.trim((string) $filters['search']).'%';
                $query->where(function (QueryBuilder $query) use ($search): void {
                    $query->where('intervals.foreign_geofence_name', 'like', $search)
                        ->orWhere('intervals.foreign_project_name', 'like', $search)
                        ->orWhere('intervals.home_project_name', 'like', $search)
                        ->orWhere('intervals.wialon_unit_id', 'like', $search)
                        ->orWhere('units.name', 'like', $search)
                        ->orWhere('units.registration_number', 'like', $search)
                        ->orWhere('types.name', 'like', $search);
                });
            });

        if (! (bool) config('fleet.foreign_geofence.show_all', false)) {
            $query->whereRaw($durationExpression.' >= ?', [(int) config('fleet.foreign_geofence.min_minutes', 180) * 60]);

            if ($status === UnitForeignGeofenceInterval::STATUS_OPEN && ! $this->monitoring->includeStaleIntervals()) {
                $query->where('intervals.last_position_at', '>=', now(config('app.timezone'))->subMinutes($this->monitoring->staleAfterMinutes()));
            }
        }

        $this->applyHomeGeofenceEligibility($query);

        return $query->select([
            'intervals.id',
            'intervals.unit_id',
            'intervals.wialon_unit_id',
            'intervals.foreign_project_id',
            'intervals.foreign_geofence_id',
            DB::raw('coalesce(foreign_projects.name, intervals.foreign_project_name) as foreign_project_name'),
            DB::raw('coalesce(foreign_geofences.name, intervals.foreign_geofence_name) as foreign_geofence_name'),
            DB::raw($durationExpression.' as sort_duration'),
        ]);
    }

    private function applyHomeGeofenceEligibility(QueryBuilder $query): void
    {
        $allowedByProject = $this->allowedHomeGeofenceIdsByProject();

        $query->where(function (QueryBuilder $query) use ($allowedByProject): void {
            foreach ($allowedByProject as $projectId => $geofenceIds) {
                $query->orWhere(function (QueryBuilder $query) use ($projectId, $geofenceIds): void {
                    $query->where('intervals.home_project_id', (int) $projectId)
                        ->where(function (QueryBuilder $query) use ($geofenceIds): void {
                            $query->whereNull('intervals.foreign_geofence_id');

                            if ($geofenceIds !== []) {
                                $query->orWhereNotIn('intervals.foreign_geofence_id', $geofenceIds);
                            }
                        });
                });
            }
        });
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function allowedHomeGeofenceIdsByProject(): array
    {
        return Project::query()
            ->where('active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(function (Project $project): array {
                $ids = $this->monitoring->resolveAllowedHomeGeofences($project)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                return $ids === [] ? [] : [(int) $project->id => $ids];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function allowedVehicleTypeSqlKeys(): array
    {
        $allowedCodes = collect($this->monitoring->allowedVehicleTypeNames())
            ->map(fn (string $type): string => FleetVehicleType::normalize($type))
            ->unique()
            ->values();

        return collect(FleetVehicleType::aliases())
            ->filter(fn (string $target): bool => $allowedCodes->contains($target))
            ->keys()
            ->merge($allowedCodes)
            ->unique()
            ->values()
            ->all();
    }

    private function durationSqlExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "coalesce(nullif(intervals.duration_seconds, 0), (strftime('%s', coalesce(intervals.left_at, intervals.last_position_at, intervals.entered_at)) - strftime('%s', intervals.entered_at)))";
        }

        return 'coalesce(nullif(intervals.duration_seconds, 0), timestampdiff(second, intervals.entered_at, coalesce(intervals.left_at, intervals.last_position_at, intervals.entered_at)))';
    }

    private function reportIntervalQuery(array $filters): Builder
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
            ->where('source', GeofenceReportViolationCalculator::SOURCE)
            ->where('status', UnitForeignGeofenceInterval::STATUS_CLOSED)
            ->where('entered_at', '<=', $to)
            ->where(function (Builder $query) use ($from): void {
                $query->where('left_at', '>=', $from)
                    ->orWhereNull('left_at');
            })
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('home_project_id', $projectId))
            ->when($ownershipType, fn (Builder $query, string $ownershipType) => $query->where('ownership_type', $ownershipType))
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
        $durationSeconds = (int) ($interval->duration_seconds ?? 0);

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

        if ($interval->status === UnitForeignGeofenceInterval::STATUS_OPEN && $this->monitoring->isStale($interval)) {
            return false;
        }

        $durationSeconds = (int) ($interval->duration_seconds ?: $this->monitoring->effectiveDurationSeconds($interval));

        return $durationSeconds >= (int) config('fleet.foreign_geofence.min_minutes', 180) * 60;
    }

    private function unitKey(UnitForeignGeofenceInterval $interval): string
    {
        return (string) ($interval->wialon_unit_id ?: $interval->unit_id ?: $interval->id);
    }

    private function recordUnitKey(object $record): string
    {
        return (string) ($record->wialon_unit_id ?: $record->unit_id ?: $record->id);
    }

    private function sectorKey(UnitForeignGeofenceInterval $interval): string
    {
        if ($interval->foreign_project_id || $interval->foreign_geofence_id) {
            return (string) ($interval->foreign_project_id ?? 'null').':'.(string) ($interval->foreign_geofence_id ?? 'null');
        }

        return 'name:'.mb_strtolower(trim((string) $interval->foreign_geofence_name));
    }

    private function recordSectorKey(object $record): string
    {
        if ($record->foreign_project_id || $record->foreign_geofence_id) {
            return (string) ($record->foreign_project_id ?? 'null').':'.(string) ($record->foreign_geofence_id ?? 'null');
        }

        return 'name:'.mb_strtolower(trim((string) $record->foreign_geofence_name));
    }

    private function recordCurrentGeozoneLabel(object $record): string
    {
        $project = trim((string) ($record->foreign_project_name ?? ''));
        $geofence = trim((string) ($record->foreign_geofence_name ?? ''));

        if ($project === '') {
            return $geofence;
        }

        if ($geofence === '' || $geofence === $project) {
            return $project;
        }

        return $project.' / '.$geofence;
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

        if ($interval->foreign_geofence_id === null || $interval->foreign_project_id === null) {
            return false;
        }

        if ($this->monitoring->homeProjectGeofences($unit)->contains('id', $interval->foreign_geofence_id)) {
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
            $row = collect($this->summary($filters)['rows'] ?? [])->first();
            $project = is_array($row) && filled($row['label'] ?? null)
                ? (string) $row['label']
                : 'foreign-project-geofence';

            $parts[] = Str::slug($project, '_');
        }

        $parts[] = $from;
        $parts[] = $to;

        return implode('_', array_filter($parts)).'.xlsx';
    }
}
