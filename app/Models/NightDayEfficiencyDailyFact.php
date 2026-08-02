<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NightDayEfficiencyDailyFact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'engine_hours_decimal' => 'decimal:2',
            'mileage_km' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'raw_row_json' => 'array',
        ];
    }
}
