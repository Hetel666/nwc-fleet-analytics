<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class WialonReportSessionLock
{
    public function run(Closure $callback): mixed
    {
        $key = (string) config('fleet.wialon.report_session_lock_key', 'wialon-report-execution');
        $store = (string) config('fleet.wialon.report_session_lock_store', 'database');
        $seconds = max(30, (int) config('fleet.wialon.report_session_lock_seconds', 300));
        $lock = Cache::store($store)->lock($key, $seconds);

        if (! $lock->get()) {
            throw new RuntimeException("Wialon report execution lock '{$key}' is busy.");
        }

        try {
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }
}
