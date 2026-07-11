<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectWialonGeofenceGroup extends Model
{
    protected $fillable = [
        'project_id',
        'wialon_resource_id',
        'wialon_resource_name',
        'wialon_geofence_group_id',
        'name',
        'zones_count',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
