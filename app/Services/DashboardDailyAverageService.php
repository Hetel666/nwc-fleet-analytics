<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardDailyAverageService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function chartData(array $filters): array
    {
        return [
            'engine_hours' => $this->metricChartData($filters, 'engine_hours'),
            'mileage' => $this->metricChartData($filters, 'mileage'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboardData(array $filters, string $metric): array
    {
        $rows = $this->dailyAverages($filters, $metric);
        $chart = $this->metricChartDataFromRows($rows, $metric);
        $dates = $rows->pluck('date')->unique()->values();
        $tableRows = [];
        $dayCards = [];
        $previousOverall = null;

        $kpis = [
            Equipment::OWNERSHIP_NWC => $this->aggregateAverage($rows->where('ownership', Equipment::OWNERSHIP_NWC)->values(), $metric),
            Equipment::OWNERSHIP_ICARE => $this->aggregateAverage($rows->where('ownership', Equipment::OWNERSHIP_ICARE)->values(), $metric),
        ];
        $kpis['TOTAL'] = $this->aggregateAverage($rows, $metric);

        foreach ($dates as $date) {
            $nwc = $rows->first(fn (array $row): bool => $row['date'] === $date && $row['ownership'] === Equipment::OWNERSHIP_NWC);
            $icare = $rows->first(fn (array $row): bool => $row['date'] === $date && $row['ownership'] === Equipment::OWNERSHIP_ICARE);
            $overall = $this->aggregateAverage(collect([$nwc, $icare])->filter()->values(), $metric);
            $trend = $previousOverall === null || $overall['average'] === null
                ? 'same'
                : ($overall['average'] > $previousOverall ? 'up' : ($overall['average'] < $previousOverall ? 'down' : 'same'));

            if ($overall['average'] !== null) {
                $previousOverall = $overall['average'];
            }

            $tableRows[] = [
                'date' => $date,
                'label' => $this->dateLabel($date),
                'nwc' => $nwc['average'] ?? null,
                'icare' => $icare['average'] ?? null,
                'average' => $overall['average'],
                'nwc_valid' => (int) ($nwc['valid_units_count'] ?? 0),
                'icare_valid' => (int) ($icare['valid_units_count'] ?? 0),
            ];

            $dayCards[] = [
                'date' => $date,
                'label' => $this->dateLabel($date),
                'nwc' => $nwc['average'] ?? null,
                'icare' => $icare['average'] ?? null,
                'trend' => $trend,
            ];
        }

        return [
            'metric' => $metric,
            'unit' => $metric === 'mileage' ? 'km' : 'saat',
            'chart' => $chart,
            'kpis' => $kpis,
            'table_rows' => $tableRows,
            'day_cards' => $dayCards,
            'has_data' => $chart['has_data'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function summaryRows(array $filters, string $metric): array
    {
        return collect($this->dashboardData($filters, $metric)['table_rows'])
            ->map(fn (array $row): array => [
                $row['date'],
                $this->formatNullableMetric($row['nwc'], $metric),
                $this->formatNullableMetric($row['icare'], $metric),
                $this->formatNullableMetric($row['average'], $metric),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    public function journalExportRows(array $filters, string $metric): array
    {
        return $this->journalRows($filters, $metric)
            ->values()
            ->map(function (array $row, int $index) use ($metric): array {
                $values = [
                    $index + 1,
                    $row['date'],
                    $row['name'],
                    $row['vehicle_type'],
                    $row['ownership'],
                    $row['project'],
                ];

                if ($metric === 'mileage') {
                    $values[] = $row['mileage'] === null ? '' : round((float) $row['mileage'], 1);
                } else {
                    $values[] = $row['engine_hours'] === null ? '' : round((float) $row['engine_hours'], 1);
                }

                $values[] = $row['data_status'];
                $values[] = $row['wialon_id'];

                return $values;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateJournal(array $filters, string $metric): LengthAwarePaginator
    {
        $rows = $this->journalRows($filters, $metric);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<string, string>
     */
    public function journalColumns(string $metric): array
    {
        return [
            'number' => '№',
            'date' => 'Tarix',
            'name' => 'Texnikanın adı',
            'vehicle_type' => 'Texnika növü',
            'ownership' => 'Mənsubiyyət',
            'project' => 'Layihə',
            $metric === 'mileage' ? 'mileage' : 'engine_hours' => $metric === 'mileage' ? 'Faktiki yürüş, km' : 'Faktiki motosaat',
            'data_status' => 'Məlumat statusu',
            'wialon_id' => 'Wialon ID',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function dailyAverages(array $filters, string $metric): Collection
    {
        $filters = $this->normalizedFilters($filters);
        $dates = $this->dates($filters['from'], $filters['to']);
        $equipment = $this->eligibleEquipment($filters, $metric);
        $stats = $this->statsFor($filters, $equipment->pluck('id')->all());
        $rows = collect();

        foreach ($dates as $date) {
            foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownership) {
                if ($filters['ownership_type'] && $filters['ownership_type'] !== $ownership) {
                    continue;
                }

                $ownershipEquipment = $equipment->where('ownership_type', $ownership);
                $sum = 0.0;
                $valid = 0;

                foreach ($ownershipEquipment as $item) {
                    $stat = $stats->get($date.'|'.$item->id);

                    if (! $this->hasValidData($stat)) {
                        continue;
                    }

                    $sum += $metric === 'mileage'
                        ? (float) $stat->distance_km
                        : (float) $stat->worked_hours;
                    $valid++;
                }

                $rows->push([
                    'date' => $date,
                    'ownership' => $ownership,
                    'average' => $valid > 0 ? round($sum / $valid, $metric === 'mileage' ? 0 : 1) : null,
                    'valid_units_count' => $valid,
                    'missing_units_count' => max(0, $ownershipEquipment->count() - $valid),
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function journalRows(array $filters, string $metric): Collection
    {
        $filters = $this->normalizedFilters($filters);
        $dates = $this->dates($filters['from'], $filters['to']);
        $equipment = $this->eligibleEquipment($filters, $metric);
        $stats = $this->statsFor($filters, $equipment->pluck('id')->all());
        $rows = collect();

        foreach ($dates as $date) {
            foreach ($equipment as $item) {
                $stat = $stats->get($date.'|'.$item->id);
                $hasData = $this->hasValidData($stat);

                $rows->push([
                    'date' => $date,
                    'name' => $item->name,
                    'vehicle_type' => $this->displayTypeName($item->type?->name),
                    'ownership' => $this->ownershipLabel($item->ownership_type),
                    'project' => $item->project?->name ?: '—',
                    'engine_hours' => $hasData ? (float) $stat->worked_hours : null,
                    'mileage' => $hasData ? (float) $stat->distance_km : null,
                    'data_status' => $hasData ? 'Məlumat var' : 'Məlumat yoxdur',
                    'wialon_id' => $item->wialon_unit_id,
                ]);
            }
        }

        return $rows
            ->when($filters['search'] !== '', function (Collection $rows) use ($filters): Collection {
                $search = Str::lower($filters['search']);

                return $rows->filter(fn (array $row): bool => Str::contains(Str::lower(implode(' ', [
                    $row['date'],
                    $row['name'],
                    $row['vehicle_type'],
                    $row['ownership'],
                    $row['project'],
                    $row['wialon_id'],
                ])), $search));
            })
            ->sortBy([
                ['date', 'asc'],
                ['ownership', 'asc'],
                ['vehicle_type', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function metricChartData(array $filters, string $metric): array
    {
        return $this->metricChartDataFromRows($this->dailyAverages($filters, $metric), $metric);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function metricChartDataFromRows(Collection $rows, string $metric): array
    {
        $dates = $rows->pluck('date')->unique()->values();
        $sameMonth = $dates
            ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('Y-m'))
            ->unique()
            ->count() === 1;

        $series = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $validCounts = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];
        $missingCounts = [
            Equipment::OWNERSHIP_NWC => [],
            Equipment::OWNERSHIP_ICARE => [],
        ];

        foreach ($dates as $date) {
            foreach ([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE] as $ownership) {
                $row = $rows->first(fn (array $item): bool => $item['date'] === $date && $item['ownership'] === $ownership);
                $series[$ownership][] = $row['average'] ?? null;
                $validCounts[$ownership][] = (int) ($row['valid_units_count'] ?? 0);
                $missingCounts[$ownership][] = (int) ($row['missing_units_count'] ?? 0);
            }
        }

        return [
            'labels' => $dates
                ->map(fn (string $date): string => $sameMonth ? CarbonImmutable::parse($date)->format('j') : CarbonImmutable::parse($date)->format('d.m'))
                ->all(),
            'dates' => $dates->all(),
            'full_dates' => $dates
                ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('d.m.Y'))
                ->all(),
            'series' => $series,
            'valid_counts' => $validCounts,
            'missing_counts' => $missingCounts,
            'has_data' => collect($series)->flatten()->filter(fn ($value): bool => $value !== null)->isNotEmpty(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregateAverage(Collection $rows, string $metric): array
    {
        $weightedSum = 0.0;
        $valid = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $rowValid = (int) ($row['valid_units_count'] ?? 0);
            $valid += $rowValid;
            $missing += (int) ($row['missing_units_count'] ?? 0);

            if (($row['average'] ?? null) !== null && $rowValid > 0) {
                $weightedSum += (float) $row['average'] * $rowValid;
            }
        }

        return [
            'average' => $valid > 0 ? round($weightedSum / $valid, $metric === 'mileage' ? 0 : 1) : null,
            'valid_units_count' => $valid,
            'missing_units_count' => $missing,
        ];
    }

    private function dateLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('az')->translatedFormat('j M');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, Equipment>
     */
    private function eligibleEquipment(array $filters, string $metric): Collection
    {
        $allowedTypes = $this->allowedTypes($metric);

        return Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn (Builder $query, int $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('equipments.ownership_type', $ownership))
            ->get()
            ->filter(fn (Equipment $item): bool => in_array($this->typeCode($item->type?->name), $allowedTypes, true))
            ->values();
    }

    /**
     * @param  array<int, int>  $equipmentIds
     * @return \Illuminate\Support\Collection<string, EquipmentDailyStat>
     */
    private function statsFor(array $filters, array $equipmentIds): Collection
    {
        if ($equipmentIds === []) {
            return collect();
        }

        return EquipmentDailyStat::query()
            ->whereBetween('stat_date', [$filters['from'], $filters['to']])
            ->whereIn('equipment_id', $equipmentIds)
            ->when($filters['project_id'], fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['ownership_type'], fn (Builder $query, string $ownership) => $query->where('ownership_type', $ownership))
            ->get()
            ->keyBy(fn (EquipmentDailyStat $stat): string => $stat->stat_date->toDateString().'|'.$stat->equipment_id);
    }

    private function hasValidData(?EquipmentDailyStat $stat): bool
    {
        if (! $stat) {
            return false;
        }

        if ($stat->calculation_status !== null && $stat->calculation_status !== 'success') {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function dates(string $from, string $to): array
    {
        return collect(CarbonPeriod::create($from, $to))
            ->map(fn ($date): string => $date->toDateString())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $filters): array
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
            'search' => trim((string) ($filters['search'] ?? '')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedTypes(string $metric): array
    {
        return array_values(config(
            $metric === 'mileage'
                ? 'fleet_efficiency.average_mileage_vehicle_types'
                : 'fleet_efficiency.average_engine_hours_vehicle_types',
            []
        ));
    }

    private function typeCode(?string $type): string
    {
        $code = Str::of((string) $type)
            ->squish()
            ->lower()
            ->replace(['-', ' '], '_')
            ->value();

        return config('fleet_efficiency.type_aliases.'.$code, $code);
    }

    private function displayTypeName(?string $type): string
    {
        return $this->typeCode($type) === 'backhoe_loader' ? 'Backhoe Loader' : ((string) $type ?: '—');
    }

    private function ownershipLabel(string $ownership): string
    {
        return $ownership === Equipment::OWNERSHIP_ICARE ? 'İCARƏ' : 'NWC';
    }

    private function formatMetricValue(float $value, string $metric): string
    {
        return $metric === 'mileage'
            ? number_format($value, 0, '.', ' ').' km'
            : number_format($value, 1).' saat';
    }

    private function formatNullableMetric(mixed $value, string $metric): string
    {
        return $value === null ? __('app.no_data') : $this->formatMetricValue((float) $value, $metric);
    }
}
