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

    protected $guarded = [];

    /** @return array<string, string> */
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
        ];
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        return collect(array_keys(self::defaults()))
            ->mapWithKeys(fn (string $key): array => [$key => (string) $this->getAttribute($key)])
            ->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
