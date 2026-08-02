<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WialonResource extends Model
{
    protected $fillable = [
        'wialon_resource_id',
        'name',
        'account_id',
        'report_templates_count',
        'geofences_count',
        'geofence_groups_count',
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

    public function geofences(): HasMany
    {
        return $this->hasMany(WialonGeofence::class, 'resource_id', 'wialon_resource_id');
    }

    public function reportTemplates(): HasMany
    {
        return $this->hasMany(WialonReportTemplate::class, 'resource_id', 'wialon_resource_id');
    }

    public function geofenceGroups(): HasMany
    {
        return $this->hasMany(WialonGeofenceGroup::class, 'resource_id', 'wialon_resource_id');
    }
}
