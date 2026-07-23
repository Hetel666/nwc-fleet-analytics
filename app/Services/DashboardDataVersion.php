<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class DashboardDataVersion
{
    public const KEY = 'dashboard:data-version';

    public function current(): int
    {
        try {
            $value = Cache::get(self::KEY);

            if (is_numeric($value)) {
                return max(1, (int) $value);
            }

            Cache::forever(self::KEY, 1);
        } catch (Throwable) {
            return 1;
        }

        return 1;
    }

    public function bump(): int
    {
        try {
            if (! Cache::has(self::KEY)) {
                Cache::forever(self::KEY, 1);
            }

            $version = Cache::increment(self::KEY);

            if (is_numeric($version)) {
                return max(1, (int) $version);
            }
        } catch (Throwable) {
            // Fall back to a non-atomic write for cache stores without increment support.
        }

        try {
            $version = $this->current() + 1;
            Cache::forever(self::KEY, $version);

            return $version;
        } catch (Throwable) {
            return 1;
        }
    }
}
