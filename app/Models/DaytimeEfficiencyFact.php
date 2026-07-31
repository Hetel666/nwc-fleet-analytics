<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DaytimeEfficiencyFact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'report_row_found' => 'boolean',
            'engine_hours_decimal' => 'decimal:4',
            'idling_hours' => 'decimal:4',
            'mileage_adjusted' => 'decimal:3',
            'beginning_at' => 'datetime',
            'end_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    protected function factDate(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?Carbon => $value === null ? null : Carbon::parse($value),
            set: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class);
    }
}
