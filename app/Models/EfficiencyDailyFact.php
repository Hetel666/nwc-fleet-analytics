<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfficiencyDailyFact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'engine_hours_decimal' => 'decimal:2',
            'engine_seconds' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'mileage_km' => 'decimal:2',
            'raw_row_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
