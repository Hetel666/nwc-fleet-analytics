<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WialonUnitGroup extends Model
{
    protected $fillable = [
        'wialon_group_id',
        'name',
        'resource_id',
        'account_id',
        'units_count',
        'linked_project_id',
        'ownership_type',
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
        return $this->hasMany(WialonUnitGroupMember::class);
    }
}
