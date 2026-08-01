<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NighttimeEfficiencySyncTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(NighttimeEfficiencySyncRun::class, 'run_id');
    }
}
