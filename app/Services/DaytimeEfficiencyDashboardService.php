<?php

namespace App\Services;

use App\Models\DaytimeEfficiencyFact;
use App\Models\Equipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class DaytimeEfficiencyDashboardService
{
    /** @return array<string, mixed> */
    public function data(array $filters): array
    {
        return [
            'summaries' => [
                'nwc' => $this->summary($filters, Equipment::OWNERSHIP_NWC),
                'icare' => $this->summary($filters, Equipment::OWNERSHIP_ICARE),
            ],
            'facts' => $this->paginate($filters),
            'last_updated_at' => $this->baseQuery($filters)->max('calculated_at'),
            'labels' => $this->categoryLabels(),
            'detail_labels' => $this->detailLabels(),
            'colors' => collect(config('daytime_efficiency.categories', []))->map(fn (array $category): string => $category['color'])->all(),
        ];
    }

    /** @return array<string, int> */
    public function summary(array $filters, string $ownership): array
    {
        $counts = $this->baseQuery($filters, false)
            ->where('ownership_type', $ownership)
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');
        $summary = collect(array_keys(config('daytime_efficiency.categories', [])))
            ->mapWithKeys(fn (string $category): array => [$category => (int) ($counts[$category] ?? 0)])
            ->all();
        $summary['total'] = array_sum($summary);

        return $summary;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $sort = (string) ($filters['sort'] ?? 'unit_name_snapshot');
        $direction = (string) ($filters['direction'] ?? 'asc');

        return $this->baseQuery($filters)
            ->with(['project:id,name', 'equipmentType:id,name'])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? config('daytime_efficiency.page_size', 50)))
            ->withQueryString();
    }

    /** @return array<string, mixed> */
    public function export(array $filters): array
    {
        $rows = $this->baseQuery($filters)
            ->orderBy('fact_date')
            ->orderBy('unit_name_snapshot')
            ->cursor()
            ->map(fn (DaytimeEfficiencyFact $fact): array => [
                $fact->fact_date?->format('Y-m-d'),
                $fact->unit_name_snapshot,
                $fact->model_name,
                $fact->manufacturer_name,
                $fact->raw_engine_hours ?? '',
                $fact->wialon_equipment_type ?: $fact->equipment_type_canonical,
                $fact->wialon_vendor ?: strtoupper($fact->ownership_type),
                $fact->year,
                $fact->raw_idling,
                $fact->raw_mileage,
                $fact->beginning_at?->timezone(config('daytime_efficiency.timezone'))->format('Y-m-d H:i:s'),
                $fact->end_at?->timezone(config('daytime_efficiency.timezone'))->format('Y-m-d H:i:s'),
                $fact->project_name_snapshot ?: 'Layihəsiz',
                $this->categoryLabels()[$fact->category] ?? $fact->category,
                $this->detailLabels()[$fact->detail_status] ?? $fact->detail_status,
            ])
            ->all();

        return [
            'filename' => 'effektivlik_gunduz_'.$filters['date_from'].'_'.$filters['date_to'].'.xlsx',
            'title' => 'Effektivlik gündüz',
            'filters' => [
                ['Dövr', $filters['date_from'].' - '.$filters['date_to']],
                ['Mənbə', (string) config('daytime_efficiency.report_template_name')],
                ['Saat qurşağı', (string) config('daytime_efficiency.timezone')],
            ],
            'sections' => [[
                'title' => 'Wialon gündüz hesabatı',
                'columns' => [
                    'Tarix', 'Qruplaşdırma', 'Model', 'İstehsalçı', 'Engine hours', 'Equipment Type',
                    'Vendor', 'Year', 'Idling', 'Mileage (adjusted)', 'Beginning', 'End', 'Layihə', 'Kateqoriya', 'Status',
                ],
                'rows' => $rows,
            ]],
        ];
    }

    private function baseQuery(array $filters, bool $includeCategory = true): Builder
    {
        return DaytimeEfficiencyFact::query()
            ->whereBetween('fact_date', [$filters['date_from'], $filters['date_to']])
            ->when($filters['project_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('project_id', $id))
            ->when($filters['equipment_type_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('equipment_type_id', $id))
            ->when($filters['ownership_type'] ?? null, fn (Builder $query, string $ownership) => $query->where(
                'ownership_type',
                mb_strtolower($ownership) === 'icare' ? Equipment::OWNERSHIP_ICARE : Equipment::OWNERSHIP_NWC
            ))
            ->when($includeCategory ? ($filters['category'] ?? null) : null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['detail_status'] ?? null, fn (Builder $query, string $status) => $query->where('detail_status', $status))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('unit_name_snapshot', 'like', '%'.$search.'%')
                        ->orWhere('wialon_unit_id', 'like', '%'.$search.'%')
                        ->orWhere('model_name', 'like', '%'.$search.'%')
                        ->orWhere('manufacturer_name', 'like', '%'.$search.'%');
                });
            });
    }

    /** @return array<string, string> */
    public function categoryLabels(): array
    {
        return [
            'between_0_and_1' => '0 - 1 saat arası işləyən',
            'between_1_and_7' => '1 - 7 saat arası işləyən',
            'between_7_and_10' => '7 - 10 saat arası işləyən',
            'no_data_or_not_working' => 'İşləməyən / Məlumatı olmayan',
            'over_10' => '10 saatdan artıq / Məlumat uyğunsuzluğu',
        ];
    }

    /** @return array<string, string> */
    public function detailLabels(): array
    {
        return [
            'normal' => 'Normal',
            'not_working' => 'İşləməyib',
            'no_data' => 'Məlumat yoxdur',
            'empty_value' => 'Məlumat boşdur',
            'parse_error' => 'Format xətası',
            'anomaly' => 'Məlumat uyğunsuzluğu',
        ];
    }
}
