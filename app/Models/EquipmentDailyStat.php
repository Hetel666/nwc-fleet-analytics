<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentDailyStat extends Model
{
    protected $fillable = [
        'stat_date',
        'equipment_id',
        'project_id',
        'ownership_type',
        'worked_hours',
        'daytime_hours',
        'overtime_hours',
        'total_hours',
        'day_status',
        'has_overtime',
        'data_available',
        'daytime_data_available',
        'overtime_data_available',
        'distance_km',
        'utilization_percent',
        'geofence_exit_count',
        'outside_geofence_minutes',
        'first_message_at',
        'last_message_at',
        'calculation_source',
        'calculation_status',
        'report_resource_id',
        'report_template_id',
        'source_group_id',
        'source_intervals_json',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'worked_hours' => 'decimal:2',
            'daytime_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'total_hours' => 'decimal:2',
            'has_overtime' => 'boolean',
            'data_available' => 'boolean',
            'daytime_data_available' => 'boolean',
            'overtime_data_available' => 'boolean',
            'distance_km' => 'decimal:2',
            'utilization_percent' => 'decimal:2',
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
            'source_intervals_json' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    protected function statDate(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?Carbon => $value === null ? null : Carbon::parse($value),
            set: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
