<?php

namespace App\Support;

use App\Models\Setting;

class ForeignGeofenceSettings
{
    public static function minimumMinutes(): int
    {
        $hours = Setting::query()
            ->where('key', 'geofence_min_exit_minutes')
            ->value('value');

        if (is_numeric($hours) && (int) $hours > 0) {
            return (int) $hours * 60;
        }

        return max(0, (int) config('fleet.foreign_geofence.min_minutes', 180));
    }
}
