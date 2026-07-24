<?php

namespace App\Contracts;

use App\Data\UnitPositionData;
use App\Models\Equipment;
use Illuminate\Support\Collection;

interface UnitPositionSource
{
    /**
     * @param  Collection<int, Equipment>  $equipment
     * @return array<int, UnitPositionData>
     */
    public function latestPositionsFor(Collection $equipment): array;
}
