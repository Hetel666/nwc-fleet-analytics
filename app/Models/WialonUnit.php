<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WialonUnit extends Model
{
    protected $fillable = [
        'wialon_unit_id',
        'name',
        'equipment_type_name',
        'ownership_type',
        'unique_id',
        'imei',
        'linked_project_id',
        'local_equipment_id',
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

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'local_equipment_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(WialonUnitGroupMember::class);
    }
}
