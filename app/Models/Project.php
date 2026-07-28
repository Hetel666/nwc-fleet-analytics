<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public const DASHBOARD_UNASSIGNED_NAMES = [
        'Layihəsiz',
        'Layihesiz',
        '-Layihəsiz-',
        '-Layihesiz-',
    ];

    protected $fillable = [
        'name',
        'code',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(EquipmentDailyStat::class);
    }

    public function wialonGroups(): HasMany
    {
        return $this->hasMany(ProjectWialonGroup::class);
    }

    public function scopeExcludeDashboardUnassigned(Builder $query): Builder
    {
        return $query->whereNotIn($query->getModel()->qualifyColumn('name'), self::DASHBOARD_UNASSIGNED_NAMES);
    }
}
