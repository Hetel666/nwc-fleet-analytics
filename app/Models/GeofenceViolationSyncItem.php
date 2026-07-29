<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceViolationSyncItem extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'checkpoint_key',
        'project_id',
        'project_wialon_group_id',
        'wialon_group_id',
        'wialon_group_name',
        'ownership_type',
        'report_period_from',
        'report_period_to',
        'status',
        'attempts',
        'source_rows',
        'imported_rows',
        'rejected_rows',
        'skipped_rows',
        'malformed_rows',
        'last_error_code',
        'last_error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'source_rows' => 'integer',
            'imported_rows' => 'integer',
            'rejected_rows' => 'integer',
            'skipped_rows' => 'integer',
            'malformed_rows' => 'integer',
            'report_period_from' => 'datetime',
            'report_period_to' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectWialonGroup(): BelongsTo
    {
        return $this->belongsTo(ProjectWialonGroup::class);
    }
}
