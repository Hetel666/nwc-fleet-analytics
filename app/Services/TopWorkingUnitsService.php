<?php

namespace App\Services;

use App\Models\EngineHoursReportUnitDay;
use App\Models\Equipment;
use App\Models\Project;
use App\Support\DashboardDateRangePolicy;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TopWorkingUnitsService
{
    public function __construct(private DashboardDateRangePolicy $dateRangePolicy) {}

    public function least(array $filters, int $limit = 20): array
    {
        return $this->rows($filters, 'least', $limit);
    }

    public function most(array $filters, int $limit = 20): array
    {
        return $this->rows($filters, 'most', $limit);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function rows(array $filters, string $ranking, int $limit = 20): array
    {
        return $this->journalRows($filters, $ranking, $limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function exportRows(array $filters, string $ranking, int $limit = 20): array
    {
        return collect($this->rows($filters, $ranking, $limit))
            ->values()
            ->map(fn (array $row, int $index): array => [
                $index + 1,
                $row['date'],
                $row['name'],
                $row['registration_number'],
                $row['ownership_label'],
                $row['type'],
                $row['project'],
                $row['hours'],
                $row['wialon_id'],
                $row['source'],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    public function exportColumns(array $filters): array
    {
        return [
            '#',
            'Tarix',
            __('app.equipment'),
            'Qeydiyyat nisani',
            'Mensubiyyet',
            __('app.type'),
            __('app.project'),
            'Faktiki '.__('app.hours'),
            'Wialon ID',
            'Menbe',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function detail(array $filters): ?array
    {
        $filters = $this->normalizeFilters($filters);
        $equipmentId = filled($filters['top_working_equipment_id'] ?? null) ? (int) $filters['top_working_equipment_id'] : null;
        $date = filled($filters['top_working_stat_date'] ?? null)
            ? CarbonImmutable::parse($filters['top_working_stat_date'])->toDateString()
            : null;

        if (! $equipmentId || ! $date) {
            return null;
        }

        $row = $this->baseQuery([...$filters, 'from' => $date, 'to' => $date])
            ->where('engine_hours_report_unit_days.equipment_id', $equipmentId)
            ->where('engine_hours_report_unit_days.stat_date', $date)
            ->first();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateDetail(array $filters): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $ranking = (string) ($filters['top_working_ranking'] ?? '');

        if (in_array($ranking, ['least', 'most'], true)) {
            $rows = $this->journalRows($filters, $ranking)
                ->values()
                ->map(fn (array $row, int $index): array => $this->detailRow($row, $index + 1))
                ->all();
        } else {
            $row = $this->detail($filters);
            $rows = $row ? [$this->detailRow($row, 1)] : [];
        }

        return new LengthAwarePaginator(
            $rows,
            count($rows),
            50,
            1,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<string, string>
     */
    public function detailColumns(): array
    {
        return [
            'number' => '#',
            'date' => 'Tarix',
            'name' => 'Texnika',
            'registration_number' => 'Qeydiyyat nisani',
            'ownership' => 'Mensubiyyet',
            'vehicle_type' => 'Texnika novu',
            'project' => 'Layihe',
            'engine_hours' => 'Engine hours',
            'wialon_id' => 'Wialon ID',
            'source' => 'Melumat menbeyi',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function journalRows(array $filters, string $ranking, ?int $limit = null): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $showDate = false;
        $query = $this->baseQuery($filters);
        $this->applyRankingOrder($query, $ranking, $filters['from'] !== $filters['to']);

        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        return $query
            ->get()
            ->map(fn ($row): array => $this->mapRow($row))
            ->map(function (array $row) use ($showDate): array {
                $row['show_date'] = $showDate;

                return $row;
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);

        $query = EngineHoursReportUnitDay::query()
            ->join('equipments', 'equipments.id', '=', 'engine_hours_report_unit_days.equipment_id')
            ->leftJoin('projects', 'projects.id', '=', 'engine_hours_report_unit_days.project_id')
            ->whereBetween('engine_hours_report_unit_days.stat_date', [$filters['from'], $filters['to']])
            ->where('equipments.active', true)
            ->where(function (Builder $query): void {
                $query->where('equipments.excluded_from_dashboard', false)
                    ->orWhereNull('equipments.excluded_from_dashboard');
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('equipments.project_wialon_group_id')
                    ->orWhereNotNull('equipments.matched_wialon_group_id');
            })
            ->whereIn('engine_hours_report_unit_days.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->where('engine_hours_report_unit_days.engine_hours_source', EngineHoursReportUnitDay::SOURCE)
            ->where('engine_hours_report_unit_days.parse_status', 'ok')
            ->whereNotNull('engine_hours_report_unit_days.engine_hours')
            ->where('engine_hours_report_unit_days.engine_hours', '>=', 0)
            ->whereNotNull('engine_hours_report_unit_days.project_id')
            ->where('projects.active', true)
            ->whereNotIn('projects.name', Project::dashboardOperationalExcludedNames())
            ->whereIn('engine_hours_report_unit_days.vehicle_type', FleetVehicleType::names(FleetVehicleType::TOP_WORKING_TYPES))
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('engine_hours_report_unit_days.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn (Builder $query, int $typeId) => $query->where('engine_hours_report_unit_days.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('engine_hours_report_unit_days.ownership_type', $ownership));

        if ($filters['from'] !== $filters['to']) {
            return $query
                ->selectRaw('MIN(engine_hours_report_unit_days.id) as stat_id')
                ->selectRaw('MIN(engine_hours_report_unit_days.stat_date) as stat_date')
                ->selectRaw('SUM(engine_hours_report_unit_days.engine_hours) as engine_hours')
                ->addSelect([
                    'engine_hours_report_unit_days.equipment_id',
                    'engine_hours_report_unit_days.ownership_type',
                    'engine_hours_report_unit_days.vehicle_type as type_name',
                    'engine_hours_report_unit_days.project_id',
                    'equipments.name',
                    'equipments.registration_number',
                    'equipments.wialon_unit_id',
                    'projects.name as project_name',
                ])
                ->groupBy([
                    'engine_hours_report_unit_days.equipment_id',
                    'engine_hours_report_unit_days.ownership_type',
                    'engine_hours_report_unit_days.vehicle_type',
                    'engine_hours_report_unit_days.project_id',
                    'equipments.name',
                    'equipments.registration_number',
                    'equipments.wialon_unit_id',
                    'projects.name',
                ]);
        }

        return $query->select([
            'engine_hours_report_unit_days.id as stat_id',
            'engine_hours_report_unit_days.stat_date',
            'engine_hours_report_unit_days.equipment_id',
            'engine_hours_report_unit_days.ownership_type',
            'engine_hours_report_unit_days.engine_hours',
            'engine_hours_report_unit_days.engine_hours_source',
            'engine_hours_report_unit_days.vehicle_type as type_name',
            'engine_hours_report_unit_days.project_id',
            'equipments.name',
            'equipments.registration_number',
            'equipments.wialon_unit_id',
            'projects.name as project_name',
        ]);
    }

    private function applyRankingOrder(Builder $query, string $ranking, bool $aggregated = false): void
    {
        $direction = $ranking === 'least' ? 'asc' : 'desc';

        $query->orderBy($aggregated ? 'engine_hours' : 'engine_hours_report_unit_days.engine_hours', $direction);

        if (! $aggregated) {
            $query->orderBy('engine_hours_report_unit_days.stat_date');
        }

        $query
            ->orderByRaw('LOWER(equipments.name) ASC')
            ->orderBy('equipments.wialon_unit_id');
    }

    private function mapRow(object $row): array
    {
        $hours = round((float) $row->engine_hours, 1);

        return [
            'id' => (int) $row->equipment_id,
            'stat_id' => (int) $row->stat_id,
            'date' => CarbonImmutable::parse($row->stat_date)->toDateString(),
            'name' => $this->equipmentName($row),
            'unit_name' => (string) ($row->name ?: ''),
            'registration_number' => $row->registration_number ?: '-',
            'type' => FleetVehicleType::display($row->type_name),
            'type_code' => FleetVehicleType::slug($row->type_name),
            'ownership' => (string) $row->ownership_type,
            'ownership_label' => $this->ownershipLabel((string) $row->ownership_type),
            'project_id' => $row->project_id ? (int) $row->project_id : null,
            'project' => $row->project_name ?: 'Layihesiz',
            'hours' => $hours,
            'distance' => 0.0,
            'work_status' => $this->workStatus($hours),
            'work_status_label' => $this->workStatusLabel($this->workStatus($hours)),
            'overtime_label' => '-',
            'data_status' => 'Melumat var',
            'wialon_id' => $row->wialon_unit_id,
            'source' => EngineHoursReportUnitDay::SOURCE,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function detailRow(array $row, int $number): array
    {
        return [
            'number' => $number,
            'date' => $row['date'],
            'name' => $row['name'],
            'registration_number' => $row['registration_number'],
            'ownership' => $row['ownership_label'],
            'vehicle_type' => $row['type'],
            'project' => $row['project'],
            'engine_hours' => $row['hours'],
            'wialon_id' => $row['wialon_id'],
            'source' => $row['source'],
        ];
    }

    private function equipmentName(object $row): string
    {
        $registration = trim((string) ($row->registration_number ?? ''));

        if ($registration !== '') {
            return $registration;
        }

        $name = trim((string) ($row->name ?? ''));

        return $name !== '' ? $name : (string) $row->wialon_unit_id;
    }

    private function workStatus(float $hours): string
    {
        if ($hours < 1) {
            return 'less_than_1';
        }

        if ($hours < 7) {
            return 'from_1_to_7';
        }

        if ($hours <= 10) {
            return 'from_7_to_10';
        }

        return 'over_10';
    }

    private function workStatusLabel(string $status): string
    {
        return match ($status) {
            'less_than_1' => '1 saatdan az isleyen',
            'from_1_to_7' => '7 saatdan az isleyen',
            'from_7_to_10' => '7-10 saat arasi isleyen',
            'over_10' => '10 saatdan cox isleyen',
            default => $status,
        };
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'ICARE' : 'NWC';
    }

    private function ownershipType(mixed $ownership): ?string
    {
        $raw = trim((string) $ownership);
        $normalized = mb_strtolower($raw);

        return match ($normalized) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => in_array(strtoupper($raw), [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)
                ? strtoupper($raw)
                : null,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $dateContext = ($filters['_date_context'] ?? null) === 'export' ? 'export' : 'modal';
        $range = $this->dateRangePolicy->normalize([
            ...$filters,
            '_default_from' => now(config('app.timezone'))->toDateString(),
            '_default_to' => $filters['from'] ?? $filters['date_from'] ?? now(config('app.timezone'))->toDateString(),
        ], $dateContext);

        $ownership = $this->ownershipType($filters['ownership_type'] ?? $filters['ownership'] ?? null);

        $ranking = $filters['top_working_ranking'] ?? null;

        return [
            '_date_context' => $dateContext,
            'from' => $range['from'],
            'to' => $range['to'],
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'equipment_type_id' => filled($filters['equipment_type_id'] ?? null) ? (int) $filters['equipment_type_id'] : null,
            'ownership_type' => $ownership,
            'top_working_equipment_id' => $filters['top_working_equipment_id'] ?? null,
            'top_working_stat_date' => $filters['top_working_stat_date'] ?? null,
            'top_working_ranking' => in_array($ranking, ['least', 'most'], true) ? $ranking : null,
        ];
    }
}
