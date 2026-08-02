<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\DashboardSectionAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_VIEWER = 'viewer';

    public const DASHBOARD_SECTION_OVERVIEW = 'overview';

    public const DASHBOARD_SECTION_EFFICIENCY = 'efficiency';

    public const DASHBOARD_SECTION_GEOZONES = 'geozones';

    public const PERMISSION_WIALON_CATALOG_VIEW = 'wialon_catalog.view';

    public const PERMISSION_WIALON_CATALOG_SYNC = 'wialon_catalog.sync';

    public const PERMISSION_PROJECTS_MANAGE = 'projects.manage';

    public const PERMISSION_DASHBOARD_VISIBILITY_MANAGE = 'dashboard_visibility.manage';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'active',
        'dashboard_sections',
        'permissions',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'dashboard_sections' => 'array',
            'permissions' => 'array',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    /** @return array<string> */
    public static function dashboardSectionKeys(): array
    {
        return [
            self::DASHBOARD_SECTION_OVERVIEW,
            self::DASHBOARD_SECTION_EFFICIENCY,
            self::DASHBOARD_SECTION_GEOZONES,
        ];
    }

    /** @return array<string, string> */
    public static function dashboardSectionOptions(): array
    {
        return [
            self::DASHBOARD_SECTION_OVERVIEW => __('app.dashboard_tab_overview'),
            self::DASHBOARD_SECTION_EFFICIENCY => __('app.dashboard_tab_efficiency'),
            self::DASHBOARD_SECTION_GEOZONES => __('app.dashboard_tab_geozones'),
        ];
    }

    /** @return array<string> */
    public static function permissionKeys(): array
    {
        return [
            self::PERMISSION_DASHBOARD_VISIBILITY_MANAGE,
            self::PERMISSION_WIALON_CATALOG_VIEW,
            self::PERMISSION_WIALON_CATALOG_SYNC,
            self::PERMISSION_PROJECTS_MANAGE,
        ];
    }

    /** @return array<string, string> */
    public static function permissionOptions(): array
    {
        return [
            self::PERMISSION_DASHBOARD_VISIBILITY_MANAGE => 'Dashboard gorunurluyunu idare etmek',
            self::PERMISSION_WIALON_CATALOG_VIEW => 'Wialon kataloquna baxış',
            self::PERMISSION_WIALON_CATALOG_SYNC => 'Wialon kataloqu sinxronizasiya',
            self::PERMISSION_PROJECTS_MANAGE => 'Layihələri yaratmaq və dəyişmək',
        ];
    }

    /** @return array<string> */
    public function allowedPermissions(): array
    {
        if ($this->isAdmin()) {
            return self::permissionKeys();
        }

        return collect($this->permissions ?? [])
            ->map(fn ($permission): string => (string) $permission)
            ->intersect(self::permissionKeys())
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->allowedPermissions(), true);
    }

    /** @return array<string> */
    public function allowedDashboardSections(): array
    {
        if ($this->isAdmin() || $this->dashboard_sections === null) {
            return self::dashboardSectionKeys();
        }

        return collect($this->dashboard_sections)
            ->map(fn ($section): string => (string) $section)
            ->intersect(self::dashboardSectionKeys())
            ->values()
            ->all();
    }

    public function canAccessDashboardSection(?string $section): bool
    {
        if ($section === null) {
            return true;
        }

        return in_array($section, $this->allowedDashboardSections(), true);
    }

    /** @return array<string, array<string, string>> */
    public function visibleDashboardTabs(): array
    {
        return DashboardSectionAccess::visibleTabsFor($this);
    }

    public function dashboardPreference(): HasOne
    {
        return $this->hasOne(UserDashboardPreference::class);
    }

    /** @return array<string, string> */
    public function resolvedDashboardPreferences(): array
    {
        if (! Schema::hasTable('user_dashboard_preferences')) {
            return UserDashboardPreference::defaults();
        }

        return $this->dashboardPreference?->settings() ?? UserDashboardPreference::defaults();
    }
}
