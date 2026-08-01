<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfficiencySyncTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EfficiencySyncRun::class, 'run_id');
    }
}
