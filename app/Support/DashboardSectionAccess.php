<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class DashboardSectionAccess
{
    /** @return array<string, array<string, string>> */
    public static function visibleTabsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_intersect_key(
            config('dashboard.tabs', []),
            array_flip($user->allowedDashboardSections())
        );
    }

    /** @return array{0: string, 1: bool} */
    public static function resolveTabForRequest(Request $request, ?string $selectedTab = null): array
    {
        $explicit = $selectedTab !== null || $request->query->has('tab');
        $rawTab = $selectedTab ?? (string) $request->query('tab', config('dashboard.default_tab', 'overview'));

        return [self::normalizeTab($rawTab), $explicit];
    }

    public static function normalizeTab(?string $tab): string
    {
        $tab = strtolower(trim((string) $tab));
        $tabs = config('dashboard.tabs', []);

        return array_key_exists($tab, $tabs)
            ? $tab
            : (string) config('dashboard.default_tab', User::DASHBOARD_SECTION_OVERVIEW);
    }

    public static function sectionForExportBlock(?string $block): string
    {
        $block = str_replace('-', '_', strtolower(trim((string) $block)));

        if (in_array($block, ['efficiency', 'daytime_efficiency', 'nighttime_efficiency', 'night_day_efficiency'], true)) {
            return User::DASHBOARD_SECTION_EFFICIENCY;
        }

        if (in_array($block, ['geofence_analysis', 'geofence_violations', 'geofence_violations_report'], true)) {
            return User::DASHBOARD_SECTION_GEOZONES;
        }

        return User::DASHBOARD_SECTION_OVERVIEW;
    }

    public static function sectionForDrilldown(Request $request): string
    {
        $mode = str_replace('-', '_', strtolower(trim((string) ($request->query('drilldown_mode') ?? $request->query('mode')))));

        if (str_contains($mode, 'efficiency')) {
            return User::DASHBOARD_SECTION_EFFICIENCY;
        }

        if ($request->boolean('geofence_violation') || str_contains($mode, 'geofence')) {
            return User::DASHBOARD_SECTION_GEOZONES;
        }

        return User::DASHBOARD_SECTION_OVERVIEW;
    }
}
