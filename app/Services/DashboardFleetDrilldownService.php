<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DashboardFleetDrilldownService
{
    public function __construct(
        private FleetEfficiencyService $efficiency,
        private DashboardDailyAverageService $dailyAverages,
        private TopWorkingUnitsService $topWorkingUnits,
        private GeofenceViolationService $geofenceViolations,
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function filters(array $input): array
    {
        $from = Carbon::parse($input['date_from'] ?? $input['from'] ?? now(config('app.timezone'))->startOfMonth())->toDateString();
        $to = Carbon::parse($input['date_to'] ?? $input['to'] ?? now(config('app.timezone')))->toDateString();
        $projectId = $input['project_id'] ?? null;
        $projectId = $projectId === '' || $projectId === 'all' ? null : $projectId;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'ownership' => $this->normalizeOwnership($input['ownership'] ?? null),
            'project_id' => filled($projectId) ? (int) $projectId : null,
            'equipment_type_id' => $this->equipmentTypeId($input),
            'metric' => $this->metric($input['metric'] ?? null),
            'top_working_equipment_id' => filled($input['top_working_equipment_id'] ?? null) ? (int) $input['top_working_equipment_id'] : null,
            'top_working_stat_date' => filled($input['top_working_stat_date'] ?? null)
                ? Carbon::parse($input['top_working_stat_date'])->toDateString()
                : null,
            'work_category' => $this->workCategory($input['work_category'] ?? null),
            'data_status' => $this->dataStatus($input['data_status'] ?? null),
            'geofence_violation' => filter_var($input['geofence_violation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'current_geozone_project_id' => filled($input['current_geozone_project_id'] ?? null) ? (int) $input['current_geozone_project_id'] : null,
            'current_geozone_id' => filled($input['current_geozone_id'] ?? null) ? (int) $input['current_geozone_id'] : null,
            'current_geozone_key' => filled($input['current_geozone_key'] ?? null) ? (string) $input['current_geozone_key'] : null,
            'search' => trim((string) ($input['search'] ?? '')),
            'sort' => $input['sort'] ?? 'name',
            'direction' => ($input['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($input['per_page'] ?? 50))),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function getUnits(array $filters): LengthAwarePaginator
    {
        if ($filters['top_working_equipment_id'] && $filters['top_working_stat_date']) {
            return $this->topWorkingUnits->paginateDetail($filters);
        }

        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->paginate($filters);
        }

        if ($filters['metric']) {
            return $this->dailyAverages->paginateJournal($this->dailyAverageFilters($filters), $filters['metric']);
        }

        if ($filters['work_category']) {
            return $this->efficiency->paginate($this->efficiencyFilters($filters));
        }

        $paginator = $this->query($filters)
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $paginator->through(fn (Equipment $equipment): array => $this->row($equipment));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function export(array $filters): array
    {
        if ($filters['metric']) {
            $rows = $this->dailyAverages->journalExportRows($this->dailyAverageFilters($filters), $filters['metric']);
        } elseif ($filters['geofence_violation']) {
            $rows = $this->geofenceViolations->exportRows($filters);
        } elseif ($filters['top_working_equipment_id'] && $filters['top_working_stat_date']) {
            $rows = $this->topWorkingUnits->paginateDetail($filters)->items();
        } elseif ($filters['work_category']) {
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
                    'title' => ($filters['work_category'] || $filters['metric']) ? 'Gündəlik jurnal' : 'Texnika siyahısı',
                    'columns' => array_values($this->columns($filters)),
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public function columns(array $filters): array
    {
        if ($filters['top_working_equipment_id'] && $filters['top_working_stat_date']) {
            return $this->topWorkingUnits->detailColumns();
        }

        if ($filters['geofence_violation']) {
            return $this->geofenceViolations->columns();
        }

        if ($filters['work_category']) {
            return [
                'number' => '№',
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
            return $this->dailyAverages->journalColumns($filters['metric']);
        }

        return [
            'number' => '№',
            'name' => 'Texnikanın adı',
            'vehicle_type' => 'Texnika növü',
            'ownership' => 'Mənsubiyyət',
            'project' => 'Layihə',
            'wialon_id' => 'Wialon ID',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public function filterSummary(array $filters): array
    {
        $summary = [
            'Dövr' => $filters['date_from'].' - '.$filters['date_to'],
            'Mənsubiyyət' => $this->ownershipLabel($filters['ownership']),
        ];

        if ($filters['project_id']) {
            $summary['Layihə'] = Project::query()->whereKey($filters['project_id'])->value('name') ?? '—';
        }

        if ($filters['equipment_type_id']) {
            $summary['Texnika növü'] = EquipmentType::query()->whereKey($filters['equipment_type_id'])->value('name') ?? '—';
        }

        if ($filters['work_category']) {
            $summary['İş statusu'] = $this->workCategoryLabel($filters['work_category']);

            if ($filters['data_status'] !== 'all') {
                $summary['Məlumat statusu'] = $this->dataStatusLabel($filters['data_status']);
            }
        }

        if ($filters['metric']) {
            $summary['Göstərici'] = $this->metricLabel($filters['metric']);
        }

        if ($filters['geofence_violation']) {
            $summary['Növ'] = 'Geozonadan çıxma halları';

            if ($filters['current_geozone_project_id']) {
                $summary['Cari geozona'] = Project::query()->whereKey($filters['current_geozone_project_id'])->value('name') ?? '—';
            }

            if ($filters['current_geozone_id']) {
                $summary['Cari geozona'] = Geofence::query()->whereKey($filters['current_geozone_id'])->value('name') ?? '—';
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
     * @param array<string, mixed> $filters
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
        }

        if ($filters['metric']) {
            $parts[] = $this->metricLabel($filters['metric']);
        }

        return implode(' — ', array_filter($parts));
    }

    /**
     * @param array<string, mixed> $filters
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
            'vehicle_type' => $equipment->type?->name ?: '—',
            'ownership' => $this->ownershipLabel($this->ownershipCode($equipment->ownership_type)),
            'project' => $equipment->project?->name ?: '—',
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
            'icare', 'icarə' => 'icare',
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
        return in_array($category, ['less_than_1', 'from_1_to_7', 'from_7_to_10', 'overtime', 'no_data'], true) ? $category : null;
    }

    private function workCategoryLabel(string $category): string
    {
        return match ($category) {
            'less_than_1' => '1 saatdan az işləyən',
            'from_1_to_7' => '7 saatdan az işləyən',
            'from_7_to_10' => '7-10 saat arası işləyən',
            'overtime' => 'İş vaxtından kənar işləyən (Overtime)',
            'no_data' => 'Məlumatı olmayan texnika',
            default => $category,
        };
    }

    private function dataStatus(?string $status): string
    {
        return in_array($status, ['available', 'missing'], true) ? $status : 'all';
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

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function efficiencyFilters(array $filters): array
    {
        return [
            'from' => $filters['date_from'],
            'to' => $filters['date_to'],
            'project_id' => $filters['project_id'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'ownership_type' => $filters['ownership'] === 'all' ? null : $this->ownershipType($filters['ownership']),
            'work_category' => $filters['work_category'],
            'data_status' => $filters['data_status'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function dailyAverageFilters(array $filters): array
    {
        return [
            'from' => $filters['date_from'],
            'to' => $filters['date_to'],
            'project_id' => $filters['project_id'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'ownership_type' => $filters['ownership'] === 'all' ? null : $this->ownershipType($filters['ownership']),
            'search' => $filters['search'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ];
    }

    /**
     * @param array<string, mixed> $input
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
     * @param array<string, mixed> $filters
     */
    private function filename(array $filters): string
    {
        if ($filters['geofence_violation'] ?? false) {
            return $this->geofenceViolations->exportFilename($filters);
        }

        return Str::slug($this->title($filters), '-').'-'.now(config('app.timezone'))->toDateString().'.xlsx';
    }
}
