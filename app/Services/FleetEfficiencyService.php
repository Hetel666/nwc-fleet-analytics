<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FleetEfficiencyService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function projectRowsByOwnership(array $filters): array
    {
        $rows = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        foreach ($this->dailyRows($filters) as $row) {
            $ownership = $row['ownership_type'];
            $projectId = (int) $row['project_id'];

            $rows[$ownership][$projectId] ??= $this->emptyProjectRow($projectId, (string) $row['project']);

            $rows[$ownership][$projectId][$row['daytime_status']]++;
            $rows[$ownership][$projectId]['total']++;
            $rows[$ownership][$projectId]['missing_data'] += $row['data_available'] ? 0 : 1;

            if ($row['has_overtime']) {
                $rows[$ownership][$projectId]['overtime']++;
                $rows[$ownership][$projectId]['total']++;
            }
        }

        foreach ($rows as &$ownershipRows) {
            $ownershipRows = collect($ownershipRows)
                ->sortBy([
                    ['total', 'desc'],
                    ['name', 'asc'],
                ])
                ->values()
                ->all();
        }
        unset($ownershipRows);

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function summaryForOwnership(array $filters, string $ownershipType): array
    {
        $summary = [
            'less_than_1' => 0,
            'from_1_to_7' => 0,
            'from_7_to_10' => 0,
            'overtime' => 0,
            'total' => 0,
            'missing_data' => 0,
        ];

        foreach ($this->dailyRows([...$filters, 'ownership_type' => $ownershipType]) as $row) {
            $summary[$row['daytime_status']]++;
            $summary['total']++;
            $summary['missing_data'] += $row['data_available'] ? 0 : 1;

            if ($row['has_overtime']) {
                $summary['overtime']++;
                $summary['total']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(array $filters): array
    {
        return $this->filterDailyRows($this->dailyRows($filters), $filters)
            ->values()
            ->map(fn (array $row, int $index): array => $this->exportRow($row, $index + 1))
            ->all();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $rows = $this->filterDailyRows($this->dailyRows($filters), $filters)->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyRows(array $filters): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $dates = collect(iterator_to_array(CarbonPeriod::create($filters['from'], $filters['to'])))
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->values();

        $equipment = $this->eligibleEquipment($filters);

        if ($equipment->isEmpty() || $dates->isEmpty()) {
            return collect();
        }

        $stats = EquipmentDailyStat::query()
            ->whereIn('equipment_id', $equipment->pluck('id')->all())
            ->whereBetween('stat_date', [$filters['from'], $filters['to']])
            ->get()
            ->keyBy(fn (EquipmentDailyStat $stat): string => $stat->equipment_id.'|'.$stat->stat_date->toDateString());

        $rows = collect();

        foreach ($equipment as $item) {
            foreach ($dates as $date) {
                $stat = $stats->get($item->id.'|'.$date);
                $rows->push($this->dailyRow($item, $date, $stat));
            }
        }

        return $rows
            ->sortBy([
                ['date', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterDailyRows(Collection $rows, array $filters): Collection
    {
        $category = $filters['work_category'] ?? null;
        $dataStatus = $filters['data_status'] ?? 'all';

        return $rows->filter(function (array $row) use ($category, $dataStatus): bool {
            if ($dataStatus === 'available' && ! $row['data_available']) {
                return false;
            }

            if ($dataStatus === 'missing' && $row['data_available']) {
                return false;
            }

            if (! $category) {
                return true;
            }

            if ($category === 'no_data') {
                return ! $row['data_available'];
            }

            if ($category === 'overtime') {
                return $row['has_overtime'];
            }

            return $row['daytime_status'] === $category;
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, Equipment>
     */
    private function eligibleEquipment(array $filters): Collection
    {
        return Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['ownership_type'], fn ($query, string $ownership) => $query->where('ownership_type', $ownership))
            ->when($filters['project_id'], fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, int $typeId) => $query->where('equipment_type_id', $typeId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Equipment $item): bool => $this->isAllowedType($item->type?->name))
            ->values();
    }

    private function dailyRow(Equipment $equipment, string $date, ?EquipmentDailyStat $stat): array
    {
        $workedHours = $stat ? (float) $stat->worked_hours : 0.0;
        $overtimeHours = $stat ? $this->overtimeHours($stat) : 0.0;
        $daytimeHours = $workedHours;
        $dataAvailable = $stat !== null;

        return [
            'date' => $date,
            'equipment_id' => $equipment->id,
            'wialon_id' => $equipment->wialon_unit_id,
            'name' => $equipment->name,
            'registration_number' => $equipment->registration_number ?: '—',
            'vehicle_type' => $this->displayType($equipment->type?->name),
            'ownership_type' => $equipment->ownership_type,
            'ownership' => $this->ownershipLabel($equipment->ownership_type),
            'project_id' => $equipment->project_id,
            'project' => $equipment->project?->name ?: '—',
            'daytime_hours' => round($daytimeHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'total_hours' => round($workedHours, 2),
            'daytime_status' => $dataAvailable ? $this->daytimeStatus($daytimeHours) : 'less_than_1',
            'daytime_status_label' => $this->statusLabel($dataAvailable ? $this->daytimeStatus($daytimeHours) : 'less_than_1'),
            'has_overtime' => $overtimeHours > 0,
            'overtime_label' => $overtimeHours > 0 ? 'Bəli' : 'Xeyr',
            'data_available' => $dataAvailable,
            'data_status' => $dataAvailable ? 'Məlumat var' : 'Məlumat yoxdur',
        ];
    }

    private function overtimeHours(EquipmentDailyStat $stat): float
    {
        if (! $stat->first_message_at || ! $stat->last_message_at) {
            return 0.0;
        }

        $timezone = config('fleet_efficiency.timezone', 'Asia/Baku');
        $start = $stat->first_message_at->copy()->timezone($timezone);
        $end = $stat->last_message_at->copy()->timezone($timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        $date = $start->toDateString();
        $windows = [
            [Carbon::parse($date.' 00:00:00', $timezone), Carbon::parse($date.' 07:59:59', $timezone)],
            [Carbon::parse($date.' 18:01:00', $timezone), Carbon::parse($date.' 23:59:59', $timezone)],
        ];

        $seconds = 0;

        foreach ($windows as [$windowStart, $windowEnd]) {
            $overlapStart = $start->greaterThan($windowStart) ? $start : $windowStart;
            $overlapEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;

            if ($overlapEnd->greaterThan($overlapStart)) {
                $seconds += $overlapStart->diffInSeconds($overlapEnd);
            }
        }

        return min((float) $stat->worked_hours, round($seconds / 3600, 2));
    }

    private function daytimeStatus(float $hours): string
    {
        if ($hours < 1) {
            return 'less_than_1';
        }

        // Business rule: in these efficiency widgets, 5 daytime hours belongs
        // to the displayed "7-10 saat" category.
        if ($hours < 5) {
            return 'from_1_to_7';
        }

        return 'from_7_to_10';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'less_than_1' => '1 saatdan az işləyən',
            'from_1_to_7' => '7 saatdan az işləyən',
            'from_7_to_10' => '7-10 saat arası işləyən',
            'overtime' => 'İş vaxtından kənar işləyən (Overtime)',
            'no_data' => 'Məlumat yoxdur',
            default => $status,
        };
    }

    private function isAllowedType(?string $name): bool
    {
        return in_array($this->typeCode($name), config('fleet_efficiency.allowed_vehicle_types', []), true);
    }

    private function displayType(?string $name): string
    {
        return $this->typeCode($name) === 'backhoe-loader' ? 'Backhoe Loader' : ($name ?: '—');
    }

    private function typeCode(?string $name): string
    {
        $slug = Str::slug(trim((string) $name));

        return config('fleet_efficiency.type_aliases.'.$slug, $slug);
    }

    private function emptyProjectRow(int $projectId, string $projectName): array
    {
        return [
            'id' => $projectId,
            'name' => $projectName,
            'less_than_1' => 0,
            'from_1_to_7' => 0,
            'from_7_to_10' => 0,
            'overtime' => 0,
            'total' => 0,
            'missing_data' => 0,
        ];
    }

    private function exportRow(array $row, int $number): array
    {
        return [
            $number,
            $row['date'],
            $row['name'],
            $row['registration_number'],
            $row['vehicle_type'],
            $row['ownership'],
            $row['project'],
            $row['data_available'] ? $row['daytime_hours'] : '—',
            $row['data_available'] ? $row['overtime_hours'] : '—',
            $row['data_available'] ? $row['total_hours'] : '—',
            $row['daytime_status_label'],
            $row['overtime_label'],
            $row['data_status'],
            $row['wialon_id'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $from = Carbon::parse($filters['from'] ?? $filters['date_from'] ?? now()->toDateString())->toDateString();
        $to = Carbon::parse($filters['to'] ?? $filters['date_to'] ?? $from)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            'equipment_type_id' => filled($filters['equipment_type_id'] ?? null) ? (int) $filters['equipment_type_id'] : null,
            'ownership_type' => $filters['ownership_type'] ?? null,
            'work_category' => $filters['work_category'] ?? null,
            'data_status' => $this->dataStatus($filters['data_status'] ?? null),
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ];
    }

    private function ownershipLabel(?string $ownershipType): string
    {
        return $ownershipType === Equipment::OWNERSHIP_ICARE ? 'İCARƏ' : 'NWC';
    }

    private function dataStatus(?string $status): string
    {
        return in_array($status, ['available', 'missing'], true) ? $status : 'all';
    }
}
