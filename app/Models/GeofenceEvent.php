<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceEvent extends Model
{
    protected $fillable = [
        'equipment_id',
        'project_id',
        'geofence_id',
        'exit_at',
        'return_at',
        'outside_minutes',
        'max_distance_meters',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'exit_at' => 'datetime',
            'return_at' => 'datetime',
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

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }
}
