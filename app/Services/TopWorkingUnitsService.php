<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TopWorkingUnitsService
{
    public function least(array $filters, int $limit = 20): array
    {
        return $this->rows($filters, 'least', $limit);
    }

    public function most(array $filters, int $limit = 20): array
    {
        return $this->rows($filters, 'most', $limit);
    }

    /**
     * Builds the exact rows used by the dashboard and Excel export.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function rows(array $filters, string $ranking, int $limit = 20): array
    {
        return $this->journalRows($filters, $ranking)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<int, mixed>>
     */
    public function exportRows(array $filters, string $ranking, int $limit = 20): array
    {
        $range = $this->isRange($filters);

        return collect($this->rows($filters, $ranking, $limit))
            ->values()
            ->map(function (array $row, int $index) use ($range): array {
                $values = [$index + 1];

                if ($range) {
                    $values[] = $row['date'];
                }

                return array_merge($values, [
                    $row['name'],
                    $row['ownership_label'],
                    $row['type'],
                    $row['project'],
                    $row['hours'],
                    $row['wialon_id'],
                ]);
            })
            ->all();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    public function exportColumns(array $filters): array
    {
        $columns = ['#'];

        if ($this->isRange($filters)) {
            $columns[] = 'Tarix';
        }

        return array_merge($columns, [
            __('app.equipment'),
            'Vendor',
            __('app.type'),
            __('app.project'),
            'Faktiki '.__('app.hours'),
            'Wialon ID',
        ]);
    }

    /**
     * @param array<string, mixed> $filters
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
            ->where('equipment_daily_stats.equipment_id', $equipmentId)
            ->whereDate('equipment_daily_stats.stat_date', $date)
            ->first();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginateDetail(array $filters): LengthAwarePaginator
    {
        $row = $this->detail($filters);
        $rows = $row ? [$this->detailRow($row)] : [];

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
            'number' => '№',
            'date' => 'Tarix',
            'name' => 'Texnikanın adı',
            'project' => 'Layihə',
            'ownership' => 'Mənsubiyyət',
            'vehicle_type' => 'Texnika növü',
            'total_hours' => 'Faktiki saat',
            'daytime_status_label' => 'İş statusu',
            'overtime_label' => 'Overtime',
            'data_status' => 'Məlumat statusu',
            'wialon_id' => 'Wialon ID',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function journalRows(array $filters, string $ranking): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $showDate = $filters['from'] !== $filters['to'];

        return $this->baseQuery($filters)
            ->get()
            ->map(fn ($row): array => $this->mapRow($row))
            ->map(function (array $row) use ($showDate): array {
                $row['show_date'] = $showDate;

                return $row;
            })
            ->filter(fn (array $row): bool => $this->isAllowedType($row['type_code']))
            ->sort(function (array $first, array $second) use ($ranking): int {
                $hours = $first['hours'] <=> $second['hours'];

                if ($hours !== 0) {
                    return $ranking === 'least' ? $hours : -$hours;
                }

                return strnatcasecmp($first['name'], $second['name'])
                    ?: strcmp((string) $first['wialon_id'], (string) $second['wialon_id']);
            })
            ->values();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);

        return EquipmentDailyStat::query()
            ->join('equipments', 'equipments.id', '=', 'equipment_daily_stats.equipment_id')
            ->leftJoin('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->leftJoin('projects', 'projects.id', '=', 'equipment_daily_stats.project_id')
            ->whereBetween('equipment_daily_stats.stat_date', [$filters['from'], $filters['to']])
            ->where('equipments.active', true)
            ->where(function (Builder $query): void {
                $query->where('equipments.excluded_from_dashboard', false)
                    ->orWhereNull('equipments.excluded_from_dashboard');
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('equipments.project_wialon_group_id')
                    ->orWhereNotNull('equipments.matched_wialon_group_id');
            })
            ->whereIn('equipment_daily_stats.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->where(function (Builder $query): void {
                $query->whereNull('equipment_daily_stats.calculation_status')
                    ->orWhere('equipment_daily_stats.calculation_status', 'success');
            })
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('equipment_daily_stats.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn (Builder $query, int $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('equipment_daily_stats.ownership_type', $ownership))
            ->select([
                'equipment_daily_stats.id as stat_id',
                'equipment_daily_stats.stat_date',
                'equipment_daily_stats.equipment_id',
                'equipment_daily_stats.ownership_type',
                'equipment_daily_stats.worked_hours',
                'equipment_daily_stats.distance_km',
                'equipment_daily_stats.first_message_at',
                'equipment_daily_stats.last_message_at',
                'equipment_daily_stats.calculation_status',
                'equipments.name',
                'equipments.registration_number',
                'equipments.wialon_unit_id',
                'equipment_types.name as type_name',
                'projects.id as project_id',
                'projects.name as project_name',
            ]);
    }

    private function mapRow(object $row): array
    {
        $hours = round((float) $row->worked_hours, 1);
        $typeCode = $this->typeCode($row->type_name);

        return [
            'id' => (int) $row->equipment_id,
            'stat_id' => (int) $row->stat_id,
            'date' => CarbonImmutable::parse($row->stat_date)->toDateString(),
            'name' => $this->equipmentName($row),
            'unit_name' => (string) ($row->name ?: ''),
            'registration_number' => $row->registration_number ?: '—',
            'type' => $this->displayType($row->type_name),
            'type_code' => $typeCode,
            'ownership' => (string) $row->ownership_type,
            'ownership_label' => $this->ownershipLabel((string) $row->ownership_type),
            'project_id' => $row->project_id ? (int) $row->project_id : null,
            'project' => $row->project_name ?: 'Layihəsiz',
            'hours' => $hours,
            'distance' => round((float) $row->distance_km, 1),
            'work_status' => $this->workStatus($hours),
            'work_status_label' => $this->workStatusLabel($this->workStatus($hours)),
            'overtime_label' => $hours > 10 ? 'Bəli' : 'Xeyr',
            'data_status' => 'Məlumat var',
            'wialon_id' => $row->wialon_unit_id,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detailRow(array $row): array
    {
        return [
            'number' => 1,
            'date' => $row['date'],
            'name' => $row['name'],
            'project' => $row['project'],
            'ownership' => $row['ownership_label'],
            'vehicle_type' => $row['type'],
            'total_hours' => $row['hours'],
            'daytime_status_label' => $row['work_status_label'],
            'overtime_label' => $row['overtime_label'],
            'data_status' => $row['data_status'],
            'wialon_id' => $row['wialon_id'],
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

    private function isAllowedType(string $typeCode): bool
    {
        return in_array($typeCode, config('fleet_efficiency.top_working_vehicle_types', []), true);
    }

    private function typeCode(?string $type): string
    {
        $code = Str::of((string) $type)
            ->squish()
            ->lower()
            ->replace(['-', ' '], '_')
            ->value();

        $alias = config('fleet_efficiency.type_aliases.'.$code, $code);

        return str_replace('-', '_', (string) $alias);
    }

    private function displayType(?string $type): string
    {
        return $this->typeCode($type) === 'backhoe_loader' ? 'Backhoe Loader' : ((string) $type ?: '—');
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

        return 'overtime';
    }

    private function workStatusLabel(string $status): string
    {
        return match ($status) {
            'less_than_1' => '1 saatdan az işləyən',
            'from_1_to_7' => '7 saatdan az işləyən',
            'from_7_to_10' => '7-10 saat arası işləyən',
            'overtime' => '10 saatdan çox işləyən (Overtime)',
            default => $status,
        };
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İCARƏ' : 'NWC';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $from = CarbonImmutable::parse($filters['date_from'] ?? $filters['from'] ?? now())->toDateString();
        $to = CarbonImmutable::parse($filters['date_to'] ?? $filters['to'] ?? $from)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $ownership = $filters['ownership_type'] ?? null;
        if (! in_array($ownership, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $ownership = null;
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'equipment_type_id' => filled($filters['equipment_type_id'] ?? null) ? (int) $filters['equipment_type_id'] : null,
            'ownership_type' => $ownership,
            'top_working_equipment_id' => $filters['top_working_equipment_id'] ?? null,
            'top_working_stat_date' => $filters['top_working_stat_date'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function isRange(array $filters): bool
    {
        $filters = $this->normalizeFilters($filters);

        return $filters['from'] !== $filters['to'];
    }
}
