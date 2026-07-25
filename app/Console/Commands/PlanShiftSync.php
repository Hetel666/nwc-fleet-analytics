<?php

namespace App\Console\Commands;

use App\Services\WialonShiftSyncService;
use Illuminate\Console\Command;

class PlanShiftSync extends Command
{
    protected $signature = 'fleet:plan-shift-sync
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--group= : Wialon group ID}
        {--project= : Project ID or exact project name}
        {--force : Re-plan completed items}';

    protected $description = 'Create missing checkpoint items for Wialon shift efficiency report synchronization.';

    public function handle(WialonShiftSyncService $sync): int
    {
        $summary = $sync->plan([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'group' => $this->option('group'),
            'project' => $this->option('project'),
            'force' => (bool) $this->option('force'),
        ]);

        $this->table(['Metric', 'Value'], collect($summary)->map(fn (int $value, string $key): array => [$key, $value])->all());

        return self::SUCCESS;
    }
}
