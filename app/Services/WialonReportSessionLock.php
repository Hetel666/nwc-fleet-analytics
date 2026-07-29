<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class WialonReportSessionLock
{
    public function run(Closure $callback): mixed
    {
        $key = (string) config('fleet.wialon.report_session_lock_key', 'wialon-report-session');
        $seconds = max(30, (int) config('fleet.wialon.report_session_lock_seconds', 300));
        $waitSeconds = max(1, (int) config('fleet.wialon.report_session_lock_wait_seconds', 300));

        return Cache::lock($key, $seconds)->block($waitSeconds, $callback);
    }
}
