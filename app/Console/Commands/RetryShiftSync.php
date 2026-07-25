<?php

namespace App\Console\Commands;

use App\Services\WialonShiftSyncService;
use Illuminate\Console\Command;

class RetryShiftSync extends Command
{
    protected $signature = 'fleet:retry-shift-sync
        {--date= : Limit to one date in YYYY-MM-DD format}
        {--group= : Limit to one Wialon group ID}
        {--all-failed : Retry all failed shift sync items}';

    protected $description = 'Move selected failed Wialon shift sync items back to retry.';

    public function handle(WialonShiftSyncService $sync): int
    {
        if (! $this->option('all-failed') && ! $this->option('date') && ! $this->option('group')) {
            $this->error('Use --date, --group, or --all-failed.');

            return self::INVALID;
        }

        $count = $sync->retryFailed([
            'date' => $this->option('date'),
            'group' => $this->option('group'),
            'all_failed' => (bool) $this->option('all-failed'),
        ]);

        $this->info("Moved {$count} failed shift sync item(s) to retry.");

        return self::SUCCESS;
    }
}
