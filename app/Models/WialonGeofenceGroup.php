<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WialonGeofenceGroup extends Model
{
    protected $fillable = [
        'wialon_geofence_group_id',
        'name',
        'resource_id',
        'resource_name',
        'geofences_count',
        'linked_project_id',
        'is_active',
        'missing_since',
        'last_seen_at',
        'last_synced_at',
        'raw_metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'missing_since' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_metadata_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'linked_project_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WialonGeofenceGroupMember::class);
    }
}
