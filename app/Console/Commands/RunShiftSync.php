<?php

namespace App\Console\Commands;

use App\Services\WialonShiftSyncService;
use Illuminate\Console\Command;

class RunShiftSync extends Command
{
    protected $signature = 'fleet:run-shift-sync
        {--limit=10 : Max sync items to process in this package}
        {--date= : Limit to one date in YYYY-MM-DD format}
        {--group= : Limit to one Wialon group ID}
        {--retry-failed : Include failed items in this run}
        {--details : Show processed item details}';

    protected $description = 'Run a small locked batch of Wialon shift efficiency report sync items.';

    public function handle(WialonShiftSyncService $sync): int
    {
        $summary = $sync->run([
            'limit' => (int) $this->option('limit'),
            'date' => $this->option('date'),
            'group' => $this->option('group'),
            'retry_failed' => (bool) $this->option('retry-failed'),
            'details' => (bool) $this->option('details'),
        ]);

        if ($summary['locked'] ?? false) {
            $this->warn('Another Wialon shift sync worker is already running.');

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except('details')
                ->map(fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : $value])
                ->all()
        );

        if ($this->option('details') && ($summary['details'] ?? []) !== []) {
            $this->table(
                ['Date', 'Group', 'Name', 'Rows received', 'Rows saved'],
                collect($summary['details'])->map(fn (array $row): array => [
                    $row['date'] ?? '',
                    $row['group'] ?? '',
                    $row['name'] ?? '',
                    $row['rows_received'] ?? 0,
                    $row['rows_saved'] ?? 0,
                ])->all()
            );
        }

        return (int) ($summary['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
