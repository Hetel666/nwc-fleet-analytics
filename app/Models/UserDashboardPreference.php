<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDashboardPreference extends Model
{
    public const LAYOUTS = ['standard', 'compact', 'card_grid', 'side_filters', 'dark_analytics'];

    public const THEMES = ['system', 'light', 'dark'];

    public const DENSITIES = ['comfortable', 'compact', 'dense'];

    public const SIDEBAR_STATES = ['expanded', 'collapsed'];

    public const LEGEND_POSITIONS = ['right', 'bottom', 'hidden'];

    public const KPI_SIZES = ['small', 'medium', 'large'];

    public const DASHBOARD_WIDGET_KEYS = [
        'ownership-share',
        'equipment-types-nwc',
        'equipment-types-icare',
        'monthly-efficiency-nwc',
        'monthly-efficiency-icare',
        'project-work-categories-nwc',
        'project-work-categories-icare',
        'daytime-efficiency-nwc',
        'daytime-efficiency-icare',
        'nighttime-efficiency-nwc',
        'nighttime-efficiency-icare',
        'night-day-efficiency-nwc',
        'night-day-efficiency-icare',
        'average-engine-hours',
        'average-mileage',
        'least-working',
        'most-working',
        'geofence-analysis',
        'geofence-violations-report',
        'utilization-trend',
        'project-comparison',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hidden_widgets' => 'array',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'layout' => 'standard',
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'donut_legend_position' => 'right',
            'table_density' => 'comfortable',
            'kpi_size' => 'medium',
            'hidden_widgets' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return collect(self::defaults())
            ->mapWithKeys(function (mixed $default, string $key): array {
                $value = $this->getAttribute($key);

                if ($key === 'hidden_widgets') {
                    $widgets = is_array($value) ? $value : $default;

                    return [$key => collect($widgets)
                        ->map(fn ($widget): string => (string) $widget)
                        ->intersect(self::DASHBOARD_WIDGET_KEYS)
                        ->values()
                        ->all()];
                }

                return [$key => (string) ($value ?? $default)];
            })
            ->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
