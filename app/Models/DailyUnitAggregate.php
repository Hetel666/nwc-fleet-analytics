<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyUnitAggregate extends Model
{
    protected $fillable = [
        'date',
        'unit_id',
        'equipment_id',
        'project_id',
        'equipment_type_id',
        'ownership_type',
        'engine_hours',
        'mileage',
        'geofence_outside_hours',
    ];

    protected function casts(): array
    {
        return [
            'engine_hours' => 'decimal:2',
            'mileage' => 'decimal:2',
            'geofence_outside_hours' => 'decimal:2',
        ];
    }

    protected function date(): Attribute
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
}
