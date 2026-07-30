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

    public const DASHBOARD_SHARE_ONLY_NAMES = [];

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

    public function scopeExcludeFromOperationalDashboard(Builder $query): Builder
    {
        return $query->whereNotIn($query->getModel()->qualifyColumn('name'), self::dashboardOperationalExcludedNames());
    }

    public static function isExcludedFromOperationalDashboard(?string $name): bool
    {
        return in_array(trim((string) $name), self::dashboardOperationalExcludedNames(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function dashboardOperationalExcludedNames(): array
    {
        return self::DASHBOARD_SHARE_ONLY_NAMES;
    }
}
