<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WialonCatalogSyncItem extends Model
{
    protected $fillable = [
        'wialon_catalog_sync_run_id',
        'section',
        'item_type',
        'wialon_id',
        'name',
        'action',
        'status',
        'error',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WialonCatalogSyncRun::class, 'wialon_catalog_sync_run_id');
    }
}
