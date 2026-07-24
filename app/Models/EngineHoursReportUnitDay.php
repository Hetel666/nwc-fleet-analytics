<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineHoursReportUnitDay extends Model
{
    public const SOURCE = 'wialon_engine_hours_report';

    protected $fillable = [
        'stat_date',
        'equipment_id',
        'project_id',
        'equipment_type_id',
        'ownership_type',
        'wialon_unit_id',
        'unit_name',
        'vehicle_type',
        'engine_hours',
        'engine_hours_source',
        'parse_status',
        'report_resource_id',
        'report_template_id',
        'report_template_name',
        'source_table',
        'engine_hours_column_index',
        'engine_hours_column_label',
        'source_group_ids_json',
        'raw_value_json',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'engine_hours' => 'decimal:2',
            'engine_hours_column_index' => 'integer',
            'source_group_ids_json' => 'array',
            'raw_value_json' => 'array',
            'synced_at' => 'datetime',
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
