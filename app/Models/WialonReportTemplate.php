<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WialonReportTemplate extends Model
{
    public const STATUS_USED = 'used';

    public const STATUS_UNUSED = 'unused';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_STRUCTURE_MISMATCH = 'structure_mismatch';

    protected $fillable = [
        'wialon_template_id',
        'name',
        'resource_id',
        'resource_name',
        'report_type',
        'tables_json',
        'used_by_modules_json',
        'usage_status',
        'is_active',
        'missing_since',
        'last_seen_at',
        'last_synced_at',
        'raw_metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'tables_json' => 'array',
            'used_by_modules_json' => 'array',
            'is_active' => 'boolean',
            'missing_since' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_metadata_json' => 'array',
        ];
    }
}
