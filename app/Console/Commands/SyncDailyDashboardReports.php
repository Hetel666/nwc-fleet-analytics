<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SyncDailyDashboardReports extends Command
{
    protected $signature = 'dashboard-reports:sync-daily
        {--date= : Business date to sync; defaults to the previous Asia/Baku calendar day}
        {--no-force : Keep already completed data instead of rebuilding the daily facts}
        {--allow-active : Queue even when an overlapping active run already exists}
        {--dry-run : Show the queue plan without creating runs}';

    protected $description = 'Queue the daily dashboard master pipeline without executing Wialon reports in the scheduler.';

    public function handle(): int
    {
        $parameters = ['--daily' => true];

        if ($this->option('date')) {
            $parameters['--date'] = (string) $this->option('date');
        }

        if (! (bool) $this->option('no-force')) {
            $parameters['--force'] = true;
        }

        if ((bool) $this->option('allow-active')) {
            $parameters['--allow-active'] = true;
        }

        if ((bool) $this->option('dry-run')) {
            $parameters['--dry-run'] = true;
        }

        return Artisan::call('dashboard-reports:queue-sync', $parameters, $this->output);
    }
}
