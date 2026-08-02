<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WialonGeofence extends Model
{
    protected $fillable = [
        'wialon_geofence_id',
        'name',
        'resource_id',
        'resource_name',
        'geofence_group_id',
        'zone_type',
        'area',
        'perimeter',
        'color',
        'linked_project_id',
        'local_geofence_id',
        'is_home_geofence',
        'is_active',
        'missing_since',
        'last_seen_at',
        'last_synced_at',
        'raw_geometry_json',
        'raw_metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'area' => 'decimal:2',
            'perimeter' => 'decimal:2',
            'is_home_geofence' => 'boolean',
            'is_active' => 'boolean',
            'missing_since' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_geometry_json' => 'array',
            'raw_metadata_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'linked_project_id');
    }

    public function localGeofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'local_geofence_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(WialonGeofenceGroupMember::class);
    }
}
