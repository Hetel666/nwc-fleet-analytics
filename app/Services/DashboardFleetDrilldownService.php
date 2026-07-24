<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardFleetDrilldownService
{
    public function __construct(
        private GeofenceViolationService $geofenceViolations,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filters(array $input): array
    {
        $from = Carbon::parse($input['date_from'] ?? $input['from'] ?? now(config('app.timezone'))->startOfMonth())->toDateString();
        $to = Carbon::parse($input['date_to'] ?? $input['to'] ?? now(config('app.timezone')))->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'project_id' => filled($input['project_id'] ?? null) ? (int) $input['project_id'] : null,
            'equipment_type_id' => $this->equipmentTypeId($input),
            'ownership' => $this->normalizeOwnership($input['ownership'] ?? null),
            'ownership_type' => $this->ownershipType($input['ownership'] ?? null),
            'metric' => $this->metric($input['metric'] ?? null),
            'work_category' => $this->workCategory($input['work_category'] ?? null),
            'data_status' => $this->dataStatus($input['data_status'] ?? null),
            'geofence_violation' => filter_var($input['geofence_violation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'current_geozone_project_id' => filled($input['current_geozone_project_id'] ?? null) ? (int) $input['current_geozone_project_id'] : null,
            'current_geozone_id' => filled($input['current_geozone_id'] ?? null) ? (int) $input['current_geozone_id'] : null,
            'current_geozone_key' => filled($input['current_geozone_key'] ?? null) ? (string) $input['current_geozone_key'] : null,
            'top_working_equipment_id' => filled($input['top_working_equipment_id'] ?? null) ? (int) $input['top_working_equipment_id'] : null,
            'top_working_stat_date' => filled($input['top_working_stat_date'] ?? null) ? Carbon::parse($input['top_working_stat_date'])->toDateString() : null,
            'search' => trim((string) ($input['search'] ?? '')),
            'sort' => (string) ($input['sort'] ?? 'name'),
            'direction' => ($input['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($input['per_page'] ?? 50))),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getUnits(array $filters): LengthAwarePaginator
    {
        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->paginate($filters);
        }

        if ($filters['top_working_equipment_id'] && $filters['top_working_stat_date']) {
            return $this->topWorkingDetailPaginator($filters);
        }

        $paginator = $this->query($filters)
            ->paginate($filters['per_page'], ['equipments.*'], 'page', $filters['page'])
            ->withQueryString();

        return $paginator->through(fn (Equipment $equipment): array => $this->row($equipment, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function export(array $filters): array
    {
        if ($filters['geofence_violation']) {
            $rows = $this->geofenceViolations->exportRows($filters);
        } elseif ($filters['top_working_equipment_id'] && $filters['top_working_stat_date']) {
            $rows = $this->topWorkingDetailRows($filters)->values()->all();
        } else {
            $rows = $this->query([...$filters, 'page' => 1, 'per_page' => 100])
                ->get()
                ->values()
                ->map(fn (Equipment $equipment, int $index): array => array_values($this->row($equipment, $filters, $index + 1)))
                ->all();
        }

        return [
            'filename' => $this->filename($filters),
            'title' => $this->title($filters),
            'filters' => collect($this->filterSummary($filters))
                ->map(fn (string $value, string $label): array => [$label, $value])
                ->values()
                ->all(),
            'sections' => [
                [
                    'title' => 'Texnika siyahısı',
                    'columns' => array_values($this->columns($filters)),
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public function columns(array $filters): array
    {
        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->columns();
        }

        return [
            'number' => '№',
            'name' => 'Texnikanın adı',
            'registration_number' => 'Qeydiyyat nişanı',
            'vehicle_type' => 'Texnika növü',
            'ownership' => 'Mənsubiyyət',
            'project' => 'Layihə',
            'worked_hours' => 'İş saatı',
            'distance_km' => 'Yürüş',
            'data_status' => 'Məlumat statusu',
            'wialon_id' => 'Wialon ID',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public function filterSummary(array $filters): array
    {
        $summary = [
            'Dövr' => $filters['date_from'].' - '.$filters['date_to'],
            'Mənsubiyyət' => $this->ownershipLabel($filters['ownership']),
        ];

        if ($filters['project_id']) {
            $summary['Layihə'] = Project::query()->whereKey($filters['project_id'])->value('name') ?? '-';
        }

        if ($filters['equipment_type_id']) {
            $summary['Texnika növü'] = EquipmentType::query()->whereKey($filters['equipment_type_id'])->value('name') ?? '-';
        }

        if ($filters['metric']) {
            $summary['Göstərici'] = $filters['metric'] === 'mileage' ? 'Yürüş' : 'Motosaat';
        }

        if ($filters['work_category']) {
            $summary['İş statusu'] = $this->workCategoryLabel($filters['work_category']);
        }

        if ($filters['geofence_violation']) {
            $summary['Növ'] = 'Geozonadan çıxma halları';
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function title(array $filters): string
    {
        if ($filters['geofence_violation']) {
            return 'Geozonadan çıxma halları';
        }

        $parts = [];
        $parts[] = $filters['metric'] === 'mileage' ? 'Yürüş' : 'Texnika';
        $parts[] = $this->ownershipLabel($filters['ownership']);

        if ($filters['work_category']) {
            $parts[] = $this->workCategoryLabel($filters['work_category']);
        }

        return implode(' — ', array_filter($parts));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $stats = $this->statsByEquipment($filters);

        $query = Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->leftJoinSub($stats, 'stats', fn ($join) => $join->on('stats.equipment_id', '=', 'equipments.id'))
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('equipments.ownership_type', $ownership))
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn (Builder $query, int $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('equipments.name', 'like', $search)
                        ->orWhere('equipments.registration_number', 'like', $search)
                        ->orWhere('equipments.wialon_unit_id', 'like', $search)
                        ->orWhereHas('type', fn (Builder $query) => $query->where('name', 'like', $search))
                        ->orWhereHas('project', fn (Builder $query) => $query->where('name', 'like', $search));
                });
            })
            ->select('equipments.*')
            ->selectRaw('COALESCE(stats.worked_hours, 0) as drilldown_worked_hours')
            ->selectRaw('COALESCE(stats.distance_km, 0) as drilldown_distance_km')
            ->selectRaw('COALESCE(stats.stat_days, 0) as drilldown_stat_days');

        if ($filters['data_status'] === 'available') {
            $query->whereRaw('COALESCE(stats.stat_days, 0) > 0');
        } elseif ($filters['data_status'] === 'missing') {
            $query->whereRaw('COALESCE(stats.stat_days, 0) = 0');
        }

        if ($filters['work_category']) {
            $this->applyWorkCategory($query, $filters['work_category']);
        }

        $this->applySort($query, $filters['sort'], $filters['direction']);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function statsByEquipment(array $filters): Builder
    {
        return EquipmentDailyStat::query()
            ->selectRaw('equipment_id, SUM(worked_hours) as worked_hours, SUM(distance_km) as distance_km, COUNT(DISTINCT stat_date) as stat_days')
            ->where('stat_date', '>=', $filters['date_from'])
            ->where('stat_date', '<', Carbon::parse($filters['date_to'])->addDay()->toDateString())
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('ownership_type', $ownership))
            ->groupBy('equipment_id');
    }

    private function applyWorkCategory(Builder $query, string $category): void
    {
        if ($category === 'no_data') {
            $query->whereRaw('COALESCE(stats.stat_days, 0) = 0');

            return;
        }

        $expression = 'CASE WHEN COALESCE(stats.stat_days, 0) > 0 THEN COALESCE(stats.worked_hours, 0) / stats.stat_days ELSE 0 END';

        match ($category) {
            'less_than_1' => $query->whereRaw("{$expression} < 1"),
            'from_1_to_7' => $query->whereRaw("{$expression} >= 1 AND {$expression} < 7"),
            'from_7_to_10' => $query->whereRaw("{$expression} >= 7 AND {$expression} <= 10"),
            'overtime' => $query->whereRaw("{$expression} > 10"),
            default => null,
        };
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'vehicle_type' => $query->join('equipment_types as sort_types', 'sort_types.id', '=', 'equipments.equipment_type_id')->orderBy('sort_types.name', $direction),
            'project' => $query->leftJoin('projects as sort_projects', 'sort_projects.id', '=', 'equipments.project_id')->orderBy('sort_projects.name', $direction),
            'ownership' => $query->orderBy('equipments.ownership_type', $direction),
            'wialon_id' => $query->orderBy('equipments.wialon_unit_id', $direction),
            default => $query->orderBy('equipments.name', $direction),
        };

        $query->orderBy('equipments.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function row(Equipment $equipment, array $filters, ?int $number = null): array
    {
        return [
            'number' => $number,
            'id' => $equipment->id,
            'name' => $equipment->name,
            'registration_number' => $equipment->registration_number ?: '-',
            'vehicle_type' => $equipment->type?->name ?: '-',
            'ownership' => $equipment->ownership_type,
            'project' => $equipment->project?->name ?: '-',
            'worked_hours' => round((float) ($equipment->drilldown_worked_hours ?? 0), 2),
            'distance_km' => round((float) ($equipment->drilldown_distance_km ?? 0), 2),
            'data_status' => ((int) ($equipment->drilldown_stat_days ?? 0)) > 0 ? 'Məlumat var' : 'Məlumat yoxdur',
            'wialon_id' => $equipment->wialon_unit_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function topWorkingDetailPaginator(array $filters): LengthAwarePaginator
    {
        $rows = $this->topWorkingDetailRows($filters);

        return new Paginator(
            $rows->forPage($filters['page'], $filters['per_page'])->values()->all(),
            $rows->count(),
            $filters['per_page'],
            $filters['page'],
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function topWorkingDetailRows(array $filters): Collection
    {
        $equipment = Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->whereKey($filters['top_working_equipment_id'])
            ->first();

        if (! $equipment instanceof Equipment) {
            return collect();
        }

        return collect([$this->row($equipment, $filters, 1)]);
    }

    private function normalizeOwnership(?string $ownership): string
    {
        return match (mb_strtolower(trim((string) $ownership))) {
            'nwc' => 'nwc',
            'icare', 'icarə' => 'icare',
            default => 'all',
        };
    }

    private function ownershipType(?string $ownership): ?string
    {
        return match ($this->normalizeOwnership($ownership)) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare' => Equipment::OWNERSHIP_ICARE,
            default => null,
        };
    }

    private function ownershipLabel(string $ownership): string
    {
        return match ($ownership) {
            'nwc' => 'NWC',
            'icare' => 'İCARƏ',
            default => 'Hamısı',
        };
    }

    private function metric(?string $metric): ?string
    {
        return in_array($metric, ['engine_hours', 'mileage'], true) ? $metric : null;
    }

    private function workCategory(?string $category): ?string
    {
        return in_array($category, ['less_than_1', 'from_1_to_7', 'from_7_to_10', 'overtime', 'no_data'], true) ? $category : null;
    }

    private function workCategoryLabel(string $category): string
    {
        return match ($category) {
            'less_than_1' => '1 saatdan az işləyən',
            'from_1_to_7' => '7 saatdan az işləyən',
            'from_7_to_10' => '7-10 saat arası işləyən',
            'overtime' => 'İş vaxtından kənar işləyən',
            'no_data' => 'Məlumatı olmayan texnika',
            default => $category,
        };
    }

    private function dataStatus(?string $status): string
    {
        return in_array($status, ['available', 'missing'], true) ? $status : 'all';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function equipmentTypeId(array $input): ?int
    {
        if (filled($input['equipment_type_id'] ?? null)) {
            return (int) $input['equipment_type_id'];
        }

        $vehicleType = trim((string) ($input['vehicle_type'] ?? ''));

        if ($vehicleType === '') {
            return null;
        }

        return EquipmentType::query()
            ->get(['id', 'name'])
            ->first(fn (EquipmentType $type): bool => Str::slug($type->name) === $vehicleType || mb_strtolower($type->name) === mb_strtolower($vehicleType))
            ?->id;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filename(array $filters): string
    {
        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->exportFilename($filters);
        }

        return Str::slug($this->title($filters), '-').'-'.now(config('app.timezone'))->toDateString().'.xlsx';
    }
}
