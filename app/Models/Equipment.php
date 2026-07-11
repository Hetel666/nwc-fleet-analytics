<?php

namespace App\Models;

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
        'ownership_type',
        'calculation_mode',
        'planned_daily_hours',
        'active',
        'last_synced_at',
        'last_position_json',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'planned_daily_hours' => 'decimal:2',
            'last_synced_at' => 'datetime',
            'last_position_json' => 'array',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(EquipmentDailyStat::class);
    }
}
