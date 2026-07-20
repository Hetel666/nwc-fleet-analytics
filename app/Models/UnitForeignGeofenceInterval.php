<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitForeignGeofenceInterval extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'unit_id',
        'wialon_unit_id',
        'source_group_id',
        'source_group_name',
        'source_group_ids_json',
        'ownership_type',
        'home_project_id',
        'home_project_name',
        'home_geofence_id',
        'home_geofence_ids_json',
        'home_geofence_names_json',
        'foreign_project_id',
        'foreign_project_name',
        'foreign_geofence_id',
        'foreign_geofence_name',
        'entered_at',
        'left_at',
        'duration_seconds',
        'status',
        'last_position_at',
        'entered_latitude',
        'entered_longitude',
        'left_latitude',
        'left_longitude',
        'report_from',
        'report_to',
        'report_resource_id',
        'report_template_id',
        'report_table_name',
        'reported_project',
        'project_mismatch',
        'match_method',
        'match_status',
        'reason',
        'source',
        'unique_key',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'left_at' => 'datetime',
            'last_position_at' => 'datetime',
            'report_from' => 'datetime',
            'report_to' => 'datetime',
            'calculated_at' => 'datetime',
            'duration_seconds' => 'integer',
            'entered_latitude' => 'decimal:7',
            'entered_longitude' => 'decimal:7',
            'left_latitude' => 'decimal:7',
            'left_longitude' => 'decimal:7',
            'source_group_ids_json' => 'array',
            'home_geofence_ids_json' => 'array',
            'home_geofence_names_json' => 'array',
            'project_mismatch' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'unit_id');
    }

    public function homeProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'home_project_id');
    }

    public function homeGeofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'home_geofence_id');
    }

    public function foreignProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'foreign_project_id');
    }

    public function foreignGeofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'foreign_geofence_id');
    }
}
