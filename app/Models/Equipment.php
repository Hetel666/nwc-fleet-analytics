<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    public const OWNERSHIP_NWC = 'NWC';

    public const OWNERSHIP_ICARE = 'ICARE';

    public const MODE_ENGINE_HOURS = 'engine_hours';

    public const MODE_IGNITION = 'ignition';

    public const MODE_MILEAGE = 'mileage';

    protected $table = 'equipments';

    protected $fillable = [
        'name',
        'registration_number',
        'wialon_unit_id',
        'equipment_type_id',
        'project_id',
        'project_wialon_group_id',
        'matched_wialon_group_id',
        'matched_wialon_group_name',
        'ownership_type',
        'calculation_mode',
        'planned_daily_hours',
        'active',
        'excluded_from_dashboard',
        'dashboard_exclusion_reason',
        'last_synced_at',
        'last_position_json',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'excluded_from_dashboard' => 'boolean',
            'planned_daily_hours' => 'decimal:2',
            'last_synced_at' => 'datetime',
            'last_position_json' => 'array',
        ];
    }

    public function scopeVisibleInDashboard(Builder $query): Builder
    {
        $column = $query->getModel()->qualifyColumn('excluded_from_dashboard');

        return $query->where(function (Builder $query) use ($column): void {
            $query->where($column, false)
                ->orWhereNull($column);
        });
    }

    public function scopeClassifiedForDashboard(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNotNull($query->getModel()->qualifyColumn('project_wialon_group_id'))
                ->orWhereNotNull($query->getModel()->qualifyColumn('matched_wialon_group_id'));
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectWialonGroup(): BelongsTo
    {
        return $this->belongsTo(ProjectWialonGroup::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(EquipmentDailyStat::class);
    }

    public function foreignGeofenceIntervals(): HasMany
    {
        return $this->hasMany(UnitForeignGeofenceInterval::class, 'unit_id');
    }
}
