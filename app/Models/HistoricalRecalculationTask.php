<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalRecalculationTask extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'historical_recalculation_id',
        'status',
        'operation',
        'stat_date',
        'project_id',
        'ownership_type',
        'attempts',
        'equipment_count',
        'error_message',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(HistoricalRecalculation::class, 'historical_recalculation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
