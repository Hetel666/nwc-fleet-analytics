<?php

namespace App\Services;

use App\Contracts\UnitPositionSource;
use App\Data\UnitPositionData;
use App\Models\Equipment;
use Illuminate\Support\Collection;

class LocalStoredUnitPositionSource implements UnitPositionSource
{
    public function latestPositionsFor(Collection $equipment): array
    {
        return $equipment
            ->mapWithKeys(function (Equipment $unit): array {
                $position = UnitPositionData::fromStoredPosition(
                    (int) $unit->id,
                    $unit->wialon_unit_id ? (string) $unit->wialon_unit_id : null,
                    is_array($unit->last_position_json) ? $unit->last_position_json : null,
                );

                return $position instanceof UnitPositionData
                    ? [(int) $unit->id => $position]
                    : [];
            })
            ->all();
    }
}
