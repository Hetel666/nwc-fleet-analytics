<?php

namespace App\Http\Requests;

use App\Models\Equipment;
use App\Models\EquipmentType;
use Illuminate\Validation\Rule;

class GeofenceViolationsDrilldownRequest extends GeofenceViolationsDashboardRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'equipment_type_id' => ['nullable', 'integer', 'exists:equipment_types,id'],
            'ownership' => ['nullable', 'string', Rule::in(['all', 'nwc', 'icare'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = parent::filters();
        $validated = $this->validated();
        $ownership = strtolower((string) ($validated['ownership'] ?? 'all'));

        if ($ownership !== 'all') {
            $filters['ownership_type'] = $ownership === 'icare'
                ? Equipment::OWNERSHIP_ICARE
                : Equipment::OWNERSHIP_NWC;
        }

        if (filled($validated['equipment_type_id'] ?? null)) {
            $filters['equipment_type'] = EquipmentType::query()
                ->whereKey((int) $validated['equipment_type_id'])
                ->value('name');
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
