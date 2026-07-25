<?php

namespace App\Console\Commands;

use App\Models\WialonReportSyncItem;
use App\Services\WialonShiftSyncService;
use Illuminate\Console\Command;

class ShiftSyncStatus extends Command
{
    protected $signature = 'fleet:shift-sync-status
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--details : Show retry/failed items}';

    protected $description = 'Show Wialon shift efficiency synchronization checkpoint status.';

    public function handle(WialonShiftSyncService $sync): int
    {
        $status = $sync->status([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ]);
        $counts = collect([
            'planned' => $status['planned'],
            WialonReportSyncItem::STATUS_PENDING => $status['counts'][WialonReportSyncItem::STATUS_PENDING] ?? 0,
            WialonReportSyncItem::STATUS_RUNNING => $status['counts'][WialonReportSyncItem::STATUS_RUNNING] ?? 0,
            WialonReportSyncItem::STATUS_COMPLETED => $status['counts'][WialonReportSyncItem::STATUS_COMPLETED] ?? 0,
            WialonReportSyncItem::STATUS_RETRY => $status['counts'][WialonReportSyncItem::STATUS_RETRY] ?? 0,
            WialonReportSyncItem::STATUS_FAILED => $status['counts'][WialonReportSyncItem::STATUS_FAILED] ?? 0,
            WialonReportSyncItem::STATUS_SKIPPED => $status['counts'][WialonReportSyncItem::STATUS_SKIPPED] ?? 0,
            'groups_total' => $status['groups_total'],
            'dates_total' => $status['dates_total'],
            'rows_received' => $status['rows_received'],
            'rows_saved' => $status['rows_saved'],
            'last_completed' => $status['last_completed'] instanceof WialonReportSyncItem ? $this->itemLabel($status['last_completed']) : '',
            'next_retry' => $status['next_retry'] instanceof WialonReportSyncItem ? $this->itemLabel($status['next_retry'], true) : '',
        ]);

        $this->table(['Metric', 'Value'], $counts->map(fn (mixed $value, string $key): array => [$key, $value])->all());

        $this->newLine();
        $this->line('By date');
        $this->table(
            ['Date', 'pending', 'running', 'completed', 'retry', 'failed', 'skipped'],
            collect($status['by_date'])->map(fn (array $row, string $date): array => [
                $date,
                $row[WialonReportSyncItem::STATUS_PENDING] ?? 0,
                $row[WialonReportSyncItem::STATUS_RUNNING] ?? 0,
                $row[WialonReportSyncItem::STATUS_COMPLETED] ?? 0,
                $row[WialonReportSyncItem::STATUS_RETRY] ?? 0,
                $row[WialonReportSyncItem::STATUS_FAILED] ?? 0,
                $row[WialonReportSyncItem::STATUS_SKIPPED] ?? 0,
            ])->values()->all()
        );

        if (($status['errors'] ?? []) !== []) {
            $this->newLine();
            $this->line('Errors');
            $this->table(['Code', 'Count'], collect($status['errors'])->map(fn (int $count, string $code): array => [$code, $count])->values()->all());
        }

        if ($this->option('details')) {
            $this->newLine();
            $this->line('Retry/failed');
            $this->table(
                ['Date', 'Group', 'Name', 'Status', 'Attempts', 'Next retry', 'Error'],
                collect($status['problem_items'])->map(fn (WialonReportSyncItem $item): array => [
                    $item->report_date?->toDateString(),
                    $item->wialon_group_id,
                    $item->wialon_group_name,
                    $item->status,
                    $item->attempts,
                    $item->next_retry_at?->toDateTimeString(),
                    mb_substr((string) $item->last_error_message, 0, 120),
                ])->all()
            );
        }

        return self::SUCCESS;
    }

    private function itemLabel(WialonReportSyncItem $item, bool $withRetry = false): string
    {
        $label = $item->report_date?->toDateString().' '.$item->wialon_group_id.' '.$item->status;

        return $withRetry && $item->next_retry_at ? $label.' at '.$item->next_retry_at->toDateTimeString() : $label;
    }
}
