<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WialonUnitGroupMember extends Model
{
    protected $fillable = [
        'wialon_unit_group_id',
        'wialon_unit_id',
        'wialon_group_id',
        'wialon_unit_item_id',
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
        return $this->belongsTo(WialonUnitGroup::class, 'wialon_unit_group_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(WialonUnit::class, 'wialon_unit_id');
    }
}
