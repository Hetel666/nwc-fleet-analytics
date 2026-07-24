<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use App\Support\DashboardDateRangePolicy;
use App\Support\FleetVehicleType;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DashboardFleetDrilldownService
{
    /**
     * @var array<int, string>
     */
    private const HIDDEN_DETAIL_COLUMNS = [
        'registration_number',
        'wialon_group',
        'home_geofence',
        'wialon_id',
    ];

    public function __construct(
        private FleetEfficiencyService $efficiency,
        private DashboardDailyAverageService $dailyAverages,
        private TopWorkingUnitsService $topWorkingUnits,
        private GeofenceViolationService $geofenceViolations,
        private DashboardDateRangePolicy $dateRangePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filters(array $input): array
    {
        $range = $this->dateRangePolicy->normalize([
            ...$input,
            '_default_from' => now(config('app.timezone'))->startOfMonth(),
            '_default_to' => now(config('app.timezone')),
        ], 'modal');
        $projectId = $input['project_id'] ?? null;
        $projectId = $projectId === '' || $projectId === 'all' ? null : $projectId;
        $workCategory = $this->workCategory($input['work_category'] ?? $input['status'] ?? null);
        $dayStatus = $this->dayStatus($input['day_status'] ?? null);
        $sort = (string) ($input['sort'] ?? '');

        return [
            'date_from' => $range['from'],
            'date_to' => $range['to'],
            'ownership' => $this->normalizeOwnership($input['ownership'] ?? null),
            'project_id' => filled($projectId) ? (int) $projectId : null,
            'project_ids' => $this->integerArray($input['project_ids'] ?? []),
            'equipment_type_id' => $this->equipmentTypeId($input),
            'vehicle_types' => $this->vehicleTypes($input['vehicle_types'] ?? []),
            'metric' => $this->metric($input['metric'] ?? null),
            'group_by' => $this->groupBy($input['group_by'] ?? null),
            'top_working_equipment_id' => filled($input['top_working_equipment_id'] ?? null) ? (int) $input['top_working_equipment_id'] : null,
            'top_working_stat_date' => filled($input['top_working_stat_date'] ?? null)
                ? Carbon::parse($input['top_working_stat_date'])->toDateString()
                : null,
            'top_working_ranking' => in_array($input['top_working_ranking'] ?? null, ['least', 'most'], true) ? $input['top_working_ranking'] : null,
            'work_category' => $workCategory,
            'day_status' => $dayStatus ?? $this->dayStatus($workCategory),
            'data_status' => $this->dataStatus($input['data_status'] ?? null),
            'has_overtime' => $this->hasOvertime($input['has_overtime'] ?? null),
            'day_hours_min' => $this->nullableFloat($input['day_hours_min'] ?? null),
            'day_hours_max' => $this->nullableFloat($input['day_hours_max'] ?? null),
            'overtime_hours_min' => $this->nullableFloat($input['overtime_hours_min'] ?? null),
            'overtime_hours_max' => $this->nullableFloat($input['overtime_hours_max'] ?? null),
            'total_hours_min' => $this->nullableFloat($input['total_hours_min'] ?? null),
            'total_hours_max' => $this->nullableFloat($input['total_hours_max'] ?? null),
            'unit_name' => trim((string) ($input['unit_name'] ?? '')),
            'registration_number' => trim((string) ($input['registration_number'] ?? '')),
            'wialon_id' => trim((string) ($input['wialon_id'] ?? '')),
            'geofence_violation' => filter_var($input['geofence_violation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'current_geozone_project_id' => filled($input['current_geozone_project_id'] ?? null) ? (int) $input['current_geozone_project_id'] : null,
            'current_geozone_id' => filled($input['current_geozone_id'] ?? null) ? (int) $input['current_geozone_id'] : null,
            'current_geozone_key' => filled($input['current_geozone_key'] ?? null) ? (string) $input['current_geozone_key'] : null,
            'search' => trim((string) ($input['search'] ?? '')),
            'sort' => $this->sort($sort !== '' ? $sort : (($workCategory || $dayStatus) ? 'date' : 'name')),
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
        if ($filters['top_working_ranking'] || ($filters['top_working_equipment_id'] && $filters['top_working_stat_date'])) {
            return $this->topWorkingUnits->paginateDetail($filters);
        }

        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->paginate($filters);
        }

        if ($filters['metric']) {
            $dailyAverageFilters = $this->dailyAverageFilters($filters);

            if (in_array($filters['group_by'], ['day', 'unit'], true)) {
                return $this->dailyAverages->paginateGrouped($dailyAverageFilters, $filters['metric'], $filters['group_by']);
            }

            return $this->dailyAverages->paginateJournal($dailyAverageFilters, $filters['metric']);
        }

        if ($filters['work_category'] || $filters['day_status']) {
            return $this->efficiency->paginate($this->efficiencyFilters($filters));
        }

        $paginator = $this->query($filters)
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $paginator->through(fn (Equipment $equipment): array => $this->row($equipment));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function export(array $filters): array
    {
        if ($filters['metric']) {
            $dailyAverageFilters = $this->dailyAverageFilters($filters);
            $filterRows = collect($this->filterSummary($filters))
                ->map(fn (string $value, string $label): array => [$label, $value])
                ->values()
                ->all();
            $summaryColumns = $this->dailyAverages->summaryColumns($filters['metric']);
            $detailColumns = $this->dailyAverages->columnsForGroup($filters['metric'], $filters['group_by']);
            $sections = [
                [
                    'title' => 'Xülasə',
                    'columns' => array_values($this->visibleColumns($summaryColumns)),
                    'rows' => $this->visibleExportRows(
                        $this->dailyAverages->summaryRows($dailyAverageFilters, $filters['metric']),
                        array_keys($summaryColumns),
                        array_keys($this->visibleColumns($summaryColumns))
                    ),
                ],
                [
                    'title' => 'Gündəlik detallar',
                    'columns' => array_values($this->visibleColumns($detailColumns)),
                    'rows' => $this->visibleExportRows(
                        $this->dailyAverages->exportRowsForGroup($dailyAverageFilters, $filters['metric'], $filters['group_by']),
                        array_keys($detailColumns),
                        array_keys($this->visibleColumns($detailColumns))
                    ),
                ],
            ];

            return [
                'filename' => $this->filename($filters),
                'title' => $this->title($filters),
                'filters' => $filterRows,
                'sections' => $sections,
                'sheets' => [
                    [
                        'name' => 'Xülasə',
                        'title' => $this->title($filters),
                        'filters' => $filterRows,
                        'sections' => [$sections[0]],
                    ],
                    [
                        'name' => 'Gündəlik detallar',
                        'title' => $this->title($filters),
                        'filters' => $filterRows,
                        'sections' => [$sections[1]],
                    ],
                ],
            ];
        } elseif ($filters['geofence_violation']) {
            $rows = $this->geofenceViolations->exportRows($filters);
        } elseif ($filters['top_working_ranking'] || ($filters['top_working_equipment_id'] && $filters['top_working_stat_date'])) {
            $rows = $this->topWorkingUnits->paginateDetail($filters)->items();
        } elseif ($filters['work_category'] || $filters['day_status']) {
            $rows = $this->efficiency->exportRows($this->efficiencyFilters($filters));
        } else {
            $rows = $this->query([...$filters, 'page' => 1, 'per_page' => 100])
                ->get()
                ->map(fn (Equipment $equipment, int $index): array => $this->exportRow($equipment, $index + 1))
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
                    'title' => ($filters['work_category'] || $filters['day_status'] || $filters['metric']) ? 'Gündəlik jurnal' : 'Texnika siyahısı',
                    'columns' => array_values($this->visibleColumns($this->rawColumns($filters))),
                    'rows' => $this->visibleExportRows(
                        $rows,
                        array_keys($this->rawColumns($filters)),
                        array_keys($this->visibleColumns($this->rawColumns($filters)))
                    ),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function resultSummary(array $filters): array
    {
        if (! $filters['metric']) {
            return [];
        }

        return [
            'average_formula' => $this->dailyAverages->formulaSummary($this->dailyAverageFilters($filters), $filters['metric']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public function columns(array $filters): array
    {
        return $this->visibleColumns($this->rawColumns($filters));
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    private function visibleColumns(array $columns): array
    {
        return collect($columns)
            ->except(self::HIDDEN_DETAIL_COLUMNS)
            ->all();
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $rows
     * @param  array<int, string>  $allKeys
     * @param  array<int, string>  $visibleKeys
     * @return array<int, array<int, mixed>>
     */
    private function visibleExportRows(array $rows, array $allKeys, array $visibleKeys): array
    {
        return collect($rows)
            ->map(fn (array $row): array => $this->visibleExportRow($row, $allKeys, $visibleKeys))
            ->all();
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @param  array<int, string>  $allKeys
     * @param  array<int, string>  $visibleKeys
     * @return array<int, mixed>
     */
    private function visibleExportRow(array $row, array $allKeys, array $visibleKeys): array
    {
        $assoc = array_is_list($row)
            ? array_combine($allKeys, array_slice(array_pad($row, count($allKeys), null), 0, count($allKeys)))
            : $row;

        return collect($visibleKeys)
            ->map(fn (string $key): mixed => $assoc[$key] ?? null)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function rawColumns(array $filters): array
    {
        if ($filters['top_working_ranking'] || ($filters['top_working_equipment_id'] && $filters['top_working_stat_date'])) {
            return $this->topWorkingUnits->detailColumns();
        }

        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->columns();
        }

        if ($filters['work_category'] || $filters['day_status']) {
            return [
                'number' => '#',
                'date' => 'Tarix',
                'name' => 'Texnikanın adı',
                'registration_number' => 'Qeydiyyat nişanı',
                'vehicle_type' => 'Texnika növü',
                'ownership' => 'Mənsubiyyət',
                'project' => 'Layihə',
                'daytime_hours' => 'Gündüz iş saatı',
                'overtime_hours' => 'Overtime saatı',
                'total_hours' => 'Ümumi iş saatı',
                'daytime_status_label' => 'Gündüz statusu',
                'overtime_label' => 'Overtime',
                'data_status' => 'Məlumat statusu',
                'wialon_id' => 'Wialon ID',
            ];
        }

        if ($filters['metric']) {
            return $this->dailyAverages->columnsForGroup($filters['metric'], $filters['group_by']);
        }

        return [
            'number' => '#',
            'name' => 'Texnikanın adı',
            'vehicle_type' => 'Texnika növü',
            'ownership' => 'Mənsubiyyət',
            'project' => 'Layihə',
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

        if ($filters['project_ids']) {
            $summary['Layihələr'] = Project::query()
                ->whereIn('id', $filters['project_ids'])
                ->orderBy('name')
                ->pluck('name')
                ->implode(', ');
        }

        if ($filters['equipment_type_id']) {
            $summary['Texnika növü'] = EquipmentType::query()->whereKey($filters['equipment_type_id'])->value('name') ?? '-';
        }

        if ($filters['vehicle_types']) {
            $summary['Texnika növləri'] = collect($filters['vehicle_types'])
                ->map(fn (string $type): string => $this->vehicleTypeLabel($type))
                ->implode(', ');
        }

        if ($filters['work_category']) {
            $summary['İş statusu'] = $this->workCategoryLabel($filters['work_category']);

            if ($filters['data_status'] !== 'all') {
                $summary['Məlumat statusu'] = $this->dataStatusLabel($filters['data_status']);
            }
        }

        if (! $filters['work_category'] && $filters['day_status']) {
            $summary['Gündüz statusu'] = $this->workCategoryLabel($filters['day_status']);
        }

        if (! $filters['work_category'] && $filters['data_status'] !== 'all') {
            $summary['Məlumat statusu'] = $this->dataStatusLabel($filters['data_status']);
        }

        if ($filters['has_overtime'] !== 'all') {
            $summary['Overtime'] = $filters['has_overtime'] === 'yes' ? 'Var' : 'Yoxdur';
        }

        foreach ([
            'Gündüz iş saatı' => ['day_hours_min', 'day_hours_max'],
            'Overtime saatı' => ['overtime_hours_min', 'overtime_hours_max'],
            'Ümumi iş saatı' => ['total_hours_min', 'total_hours_max'],
        ] as $label => [$minKey, $maxKey]) {
            if ($filters[$minKey] !== null || $filters[$maxKey] !== null) {
                $summary[$label] = ($filters[$minKey] ?? '0').' - '.($filters[$maxKey] ?? '∞');
            }
        }

        if ($filters['unit_name'] !== '') {
            $summary['Texnika'] = $filters['unit_name'];
        }

        if ($filters['registration_number'] !== '') {
            $summary['Qeydiyyat nişanı'] = $filters['registration_number'];
        }

        if ($filters['wialon_id'] !== '') {
            $summary['Wialon ID'] = $filters['wialon_id'];
        }

        if ($filters['metric']) {
            $summary['Göstərici'] = $this->metricLabel($filters['metric']);

            if ($filters['group_by'] !== 'details') {
                $summary['Qruplaşdırma'] = $filters['group_by'] === 'day' ? 'Gün üzrə' : 'Texnika üzrə';
            }
        }

        if ($filters['geofence_violation']) {
            $summary['Növ'] = 'Geozonadan çıxma halları';

            if ($filters['current_geozone_project_id']) {
                $summary['Cari geozona'] = Project::query()->whereKey($filters['current_geozone_project_id'])->value('name') ?? '-';
            }

            if ($filters['current_geozone_id']) {
                $summary['Cari geozona'] = Geofence::query()->whereKey($filters['current_geozone_id'])->value('name') ?? '-';
            }

            if (! $filters['current_geozone_id'] && $filters['current_geozone_key']) {
                $summary['Cari geozona'] = $filters['current_geozone_key'];
            }
        }

        if ($filters['search'] !== '') {
            $summary['Axtarış'] = $filters['search'];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function title(array $filters): string
    {
        $parts = [];

        if ($filters['project_id']) {
            $parts[] = Project::query()->whereKey($filters['project_id'])->value('name') ?? 'Layihə';
        }

        $parts[] = $this->ownershipLabel($filters['ownership']);

        if ($filters['equipment_type_id']) {
            $parts[] = EquipmentType::query()->whereKey($filters['equipment_type_id'])->value('name') ?? 'Texnika növü';
        }

        if ($filters['work_category']) {
            $parts[] = $this->workCategoryLabel($filters['work_category']);
        } elseif ($filters['day_status']) {
            $parts[] = $this->workCategoryLabel($filters['day_status']);
        }

        if ($filters['metric']) {
            $parts[] = $this->metricLabel($filters['metric']);
        }

        return implode(' - ', array_filter($parts));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $query = Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership'] !== 'all', fn (Builder $query) => $query->where('equipments.ownership_type', $this->ownershipType($filters['ownership'])))
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
            ->select('equipments.*');

        $this->applySort($query, $filters['sort'], $filters['direction']);

        return $query;
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
     * @return array<string, mixed>
     */
    private function row(Equipment $equipment): array
    {
        return [
            'id' => $equipment->id,
            'wialon_id' => $equipment->wialon_unit_id,
            'name' => $equipment->name,
            'vehicle_type' => $equipment->type?->name ?: '-',
            'ownership' => $this->ownershipLabel($this->ownershipCode($equipment->ownership_type)),
            'project' => $equipment->project?->name ?: '-',
        ];
    }

    private function exportRow(Equipment $equipment, int $number): array
    {
        $row = $this->row($equipment);

        return [
            $number,
            $row['name'],
            $row['vehicle_type'],
            $row['ownership'],
            $row['project'],
            $row['wialon_id'],
        ];
    }

    private function normalizeOwnership(?string $ownership): string
    {
        return match (mb_strtolower(trim((string) $ownership))) {
            'nwc' => 'nwc',
            'icare', 'icarə', 'icarƏ' => 'icare',
            default => 'all',
        };
    }

    private function ownershipType(string $ownership): string
    {
        return $ownership === 'icare' ? Equipment::OWNERSHIP_ICARE : Equipment::OWNERSHIP_NWC;
    }

    private function ownershipCode(?string $ownershipType): string
    {
        return $ownershipType === Equipment::OWNERSHIP_ICARE ? 'icare' : 'nwc';
    }

    private function ownershipLabel(string $ownership): string
    {
        return match ($ownership) {
            'nwc' => 'NWC',
            'icare' => 'İCARƏ',
            default => 'Bütün texnikalar',
        };
    }

    private function workCategory(?string $category): ?string
    {
        return match ($category) {
            'less_than_1', 'less_than_1_hour' => 'less_than_1_hour',
            'from_1_to_7', 'less_than_7_hours' => 'less_than_7_hours',
            'from_7_to_10', 'between_7_and_10_hours' => 'between_7_and_10_hours',
            'over_10_hours', 'over_10_day_hours' => 'over_10_hours',
            'overtime' => 'overtime',
            'no_data' => 'no_data',
            default => null,
        };
    }

    private function dayStatus(?string $status): ?string
    {
        return match ($status) {
            'less_than_1', 'less_than_1_hour' => 'less_than_1_hour',
            'from_1_to_7', 'less_than_7_hours' => 'less_than_7_hours',
            'from_7_to_10', 'between_7_and_10_hours' => 'between_7_and_10_hours',
            'over_10_hours', 'over_10_day_hours' => 'over_10_hours',
            default => null,
        };
    }

    private function workCategoryLabel(string $category): string
    {
        return match ($category) {
            'less_than_1_hour' => __('app.worked_less_than_1_hour'),
            'less_than_7_hours' => __('app.worked_less_than_7_hours'),
            'between_7_and_10_hours' => __('app.worked_7_to_10_hours'),
            'over_10_hours', 'over_10_day_hours' => __('app.worked_over_10_hours'),
            'less_than_1' => __('app.worked_less_than_1_hour'),
            'from_1_to_7' => __('app.worked_less_than_7_hours'),
            'from_7_to_10' => __('app.worked_7_to_10_hours'),
            'overtime' => __('app.worked_overtime_hours'),
            'no_data' => 'Məlumatı olmayan texnika',
            default => $category,
        };
    }

    private function dataStatus(?string $status): string
    {
        return in_array($status, ['available', 'missing'], true) ? $status : 'all';
    }

    private function hasOvertime(?string $status): string
    {
        return in_array($status, ['yes', 'no'], true) ? $status : 'all';
    }

    private function dataStatusLabel(string $status): string
    {
        return match ($status) {
            'available' => 'Məlumat var',
            'missing' => 'Məlumat yoxdur',
            default => 'Hamısı',
        };
    }

    private function metric(?string $metric): ?string
    {
        return in_array($metric, ['engine_hours', 'mileage'], true) ? $metric : null;
    }

    private function metricLabel(string $metric): string
    {
        return $metric === 'mileage' ? 'Orta yürüş' : 'Orta motosaat';
    }

    private function groupBy(?string $groupBy): string
    {
        return in_array($groupBy, ['day', 'unit'], true) ? $groupBy : 'details';
    }

    /**
     * @return array<int, int>
     */
    private function integerArray(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => filled($item))
            ->map(fn (mixed $item): int => (int) $item)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function vehicleTypes(mixed $value): array
    {
        $allowed = config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', []));

        return collect(is_array($value) ? $value : [$value])
            ->map(fn (mixed $item): string => FleetVehicleType::slug((string) $item))
            ->filter(fn (string $slug): bool => in_array($slug, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function vehicleTypeLabel(string $type): string
    {
        return FleetVehicleType::label($type);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (float) $value);
    }

    private function sort(string $sort): string
    {
        return in_array($sort, [
            'date',
            'name',
            'registration_number',
            'vehicle_type',
            'project',
            'ownership',
            'daytime_hours',
            'overtime_hours',
            'total_hours',
            'engine_hours',
            'mileage',
            'data_status',
            'wialon_id',
        ], true) ? $sort : 'name';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function efficiencyFilters(array $filters): array
    {
        return [
            'from' => $filters['date_from'],
            'to' => $filters['date_to'],
            'project_id' => $filters['project_id'],
            'project_ids' => $filters['project_ids'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'vehicle_types' => $filters['vehicle_types'],
            'group_by' => $filters['group_by'],
            'ownership_type' => $filters['ownership'] === 'all' ? null : $this->ownershipType($filters['ownership']),
            'work_category' => $filters['work_category'],
            'day_status' => $filters['day_status'],
            'data_status' => $filters['data_status'],
            'has_overtime' => $filters['has_overtime'],
            'day_hours_min' => $filters['day_hours_min'],
            'day_hours_max' => $filters['day_hours_max'],
            'overtime_hours_min' => $filters['overtime_hours_min'],
            'overtime_hours_max' => $filters['overtime_hours_max'],
            'total_hours_min' => $filters['total_hours_min'],
            'total_hours_max' => $filters['total_hours_max'],
            'unit_name' => $filters['unit_name'],
            'registration_number' => $filters['registration_number'],
            'wialon_id' => $filters['wialon_id'],
            'search' => $filters['search'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function dailyAverageFilters(array $filters): array
    {
        return [
            'from' => $filters['date_from'],
            'to' => $filters['date_to'],
            'project_id' => $filters['project_id'],
            'project_ids' => $filters['project_ids'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'vehicle_types' => $filters['vehicle_types'],
            'ownership_type' => $filters['ownership'] === 'all' ? null : $this->ownershipType($filters['ownership']),
            'data_status' => $filters['data_status'],
            'unit_name' => $filters['unit_name'],
            'registration_number' => $filters['registration_number'],
            'wialon_id' => $filters['wialon_id'],
            'search' => $filters['search'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ];
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

        if (ctype_digit($vehicleType)) {
            return (int) $vehicleType;
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
        if ($filters['geofence_violation'] ?? false) {
            return $this->geofenceViolations->exportFilename($filters);
        }

        if ($filters['work_category'] || $filters['day_status']) {
            return Str::slug($this->title($filters), '_').'_'.$filters['date_from'].'_'.$filters['date_to'].'.xlsx';
        }

        return Str::slug($this->title($filters), '-').'-'.now(config('app.timezone'))->toDateString().'.xlsx';
    }
}
