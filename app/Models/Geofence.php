<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Geofence extends Model
{
    protected $fillable = [
        'name',
        'project_id',
        'wialon_geofence_id',
        'geometry_json',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'geometry_json' => 'array',
            'active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GeofenceEvent::class);
    }
}
