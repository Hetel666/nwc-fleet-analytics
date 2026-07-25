<?php

namespace App\Services;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FleetOwnershipStatsService
{
    public function summary(array $filters = []): array
    {
        $rows = $this->baseQuery($filters)
            ->select('equipments.ownership_type', DB::raw('COUNT(DISTINCT equipments.wialon_unit_id) as total'))
            ->groupBy('equipments.ownership_type')
            ->pluck('total', 'equipments.ownership_type');

        $nwcCount = (int) ($rows[Equipment::OWNERSHIP_NWC] ?? 0);
        $icareCount = (int) ($rows[Equipment::OWNERSHIP_ICARE] ?? 0);
        $total = $nwcCount + $icareCount;

        return [
            'nwc_count' => $nwcCount,
            'icare_count' => $icareCount,
            'total_count' => $total,
            'nwc_percent' => $total > 0 ? round($nwcCount / $total * 100, 1) : 0.0,
            'icare_percent' => $total > 0 ? round($icareCount / $total * 100, 1) : 0.0,
            'rows' => [
                ['label' => Equipment::OWNERSHIP_NWC, 'count' => $nwcCount],
                ['label' => Equipment::OWNERSHIP_ICARE, 'count' => $icareCount],
            ],
        ];
    }

    public function export(array $filters = [], ?string $type = null): array
    {
        $ownershipType = $this->exportOwnershipType($type);
        $rows = $this->baseQuery($filters, $ownershipType)
            ->with([
                'project:id,name',
                'projectWialonGroup:id,name,wialon_group_id,ownership_type',
            ])
            ->orderBy('equipments.name')
            ->orderBy('equipments.wialon_unit_id')
            ->get([
                'equipments.id',
                'equipments.name',
                'equipments.wialon_unit_id',
                'equipments.project_id',
                'equipments.project_wialon_group_id',
                'equipments.matched_wialon_group_id',
                'equipments.matched_wialon_group_name',
                'equipments.ownership_type',
            ])
            ->unique('wialon_unit_id')
            ->values()
            ->map(fn (Equipment $equipment, int $index): array => [
                $index + 1,
                $equipment->name,
                $this->ownershipLabel($equipment->ownership_type),
                $equipment->projectWialonGroup?->name ?? $equipment->matched_wialon_group_name ?? '',
                $equipment->project?->name ?? 'Layihəsiz',
                $equipment->wialon_unit_id,
            ])
            ->all();

        return [
            'filename' => 'nwc-icare-texnika-siyahisi-'.now(config('app.timezone'))->toDateString().'.xlsx',
            'title' => 'NWC və İCARƏ payı',
            'filters' => [
                ['Mənsubiyyət', $ownershipType ? $this->ownershipLabel($ownershipType) : 'NWC + İCARƏ'],
                ['Yaradıldı', now(config('app.timezone'))->format('Y-m-d H:i:s')],
            ],
            'sections' => [
                [
                    'title' => 'Texnika siyahısı',
                    'columns' => ['№', 'Texnikanın adı', 'Mənsubiyyət', 'Wialon qrupu', 'Layihə', 'Wialon ID'],
                    'rows' => $rows,
                ],
            ],
        ];
    }

    private function baseQuery(array $filters = [], ?string $ownershipType = null): Builder
    {
        $ownershipType ??= $filters['ownership_type'] ?? null;

        return Equipment::query()
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->whereIn('equipments.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->boundToProjectWialonGroup()
            ->when($ownershipType, fn (Builder $query, string $type) => $query->where('equipments.ownership_type', $type))
            ->when($filters['project_id'] ?? null, fn (Builder $query, $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'] ?? null, fn (Builder $query, $typeId) => $query->where('equipments.equipment_type_id', $typeId));
    }

    private function exportOwnershipType(?string $type): ?string
    {
        return match (mb_strtolower(trim((string) $type))) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => null,
        };
    }

    private function ownershipLabel(?string $ownershipType): string
    {
        return $ownershipType === Equipment::OWNERSHIP_ICARE ? 'İCARƏ' : 'NWC';
    }
}
