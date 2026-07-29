<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WialonSyncCheckpoint extends Model
{
    public const TYPE_DAILY_ENGINE_STATS = 'daily_engine_stats';

    protected $fillable = [
        'checkpoint_key',
        'sync_type',
        'report_date',
        'project_id',
        'ownership_type',
        'wialon_group_id',
        'status',
        'equipment_count',
        'payload',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'project_id' => 'integer',
            'equipment_count' => 'integer',
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
