<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WialonGeofenceGroupMember extends Model
{
    protected $fillable = [
        'wialon_geofence_group_id',
        'wialon_geofence_id',
        'resource_id',
        'wialon_geofence_group_item_id',
        'wialon_geofence_item_id',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WialonGeofenceGroup::class, 'wialon_geofence_group_id');
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(WialonGeofence::class, 'wialon_geofence_id');
    }
}
