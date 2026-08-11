<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalRecalculation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPERATION_FETCH = 'fetch';

    public const OPERATION_RECALCULATE = 'recalculate';

    public const OPERATION_FETCH_AND_RECALCULATE = 'fetch_and_recalculate';

    public const SECTION_DAILY_AVERAGES = 'daily_averages';

    public const SECTION_EFFICIENCY = 'efficiency';

    public const SECTION_DAYTIME_EFFICIENCY = 'daytime_efficiency';

    public const SECTION_NIGHTTIME_EFFICIENCY = 'nighttime_efficiency';

    public const SECTION_NIGHT_DAY_EFFICIENCY = 'night_day_efficiency';

    public const SECTION_MONTHLY_EFFICIENCY = 'monthly_efficiency';

    public const SECTION_TOP_WORKING_UNITS = 'top_working_units';

    public const SECTION_GEOFENCE_OUTSIDE = 'geofence_outside';

    public const SECTION_GEOFENCE_VIOLATIONS = 'geofence_violations';

    public const SECTION_ALL_DASHBOARDS = 'all_dashboards';

    public const SCOPE_ALL_PROJECTS = 'all_projects';

    public const SCOPE_SELECTED_PROJECTS = 'selected_projects';

    protected $fillable = [
        'uuid',
        'signature',
        'status',
        'dashboard_section',
        'operation',
        'scope',
        'date_from',
        'date_to',
        'timezone',
        'force',
        'project_ids',
        'options_json',
        'total_tasks',
        'completed_tasks',
        'failed_tasks',
        'cancelled_tasks',
        'processed_objects',
        'batch_id',
        'requested_by',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
        'error_summary',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'force' => 'boolean',
            'project_ids' => 'array',
            'options_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(HistoricalRecalculationTask::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_ERRORS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
