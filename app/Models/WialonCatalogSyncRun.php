<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WialonCatalogSyncRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'sync_type',
        'sections_json',
        'status',
        'started_by',
        'added_count',
        'updated_count',
        'deactivated_count',
        'error_count',
        'duration_ms',
        'last_error',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'sections_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WialonCatalogSyncItem::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_RETRYING,
        ], true);
    }
}
