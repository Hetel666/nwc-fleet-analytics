<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NighttimeEfficiencyDailyFact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'shift_started_at' => 'datetime',
            'shift_ended_at' => 'datetime',
            'engine_hours_decimal' => 'decimal:2',
            'mileage_km' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'source_parts_json' => 'array',
            'raw_row_json' => 'array',
        ];
    }
}
