<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WialonReportSyncItem extends Model
{
    public const TYPE_SHIFT_EFFICIENCY = 'shift_efficiency';
    public const TYPE_ENGINE_HOURS_TOP20 = 'engine_hours_top20';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RETRY = 'retry';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'sync_type',
        'report_date',
        'wialon_group_id',
        'wialon_group_name',
        'status',
        'attempts',
        'rows_received',
        'rows_saved',
        'started_at',
        'finished_at',
        'next_retry_at',
        'last_error_code',
        'last_error_message',
        'run_id',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'rows_received' => 'integer',
            'rows_saved' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    protected function reportDate(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?Carbon => $value === null ? null : Carbon::parse($value),
            set: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }
}
