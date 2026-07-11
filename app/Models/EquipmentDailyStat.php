<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentDailyStat extends Model
{
    protected $fillable = [
        'stat_date',
        'equipment_id',
        'project_id',
        'ownership_type',
        'worked_hours',
        'distance_km',
        'utilization_percent',
        'geofence_exit_count',
        'outside_geofence_minutes',
        'first_message_at',
        'last_message_at',
        'calculation_source',
        'calculation_status',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'worked_hours' => 'decimal:2',
            'distance_km' => 'decimal:2',
            'utilization_percent' => 'decimal:2',
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
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
