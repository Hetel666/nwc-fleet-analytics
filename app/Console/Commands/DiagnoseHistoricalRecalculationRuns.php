<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Services\HistoricalRecalculationService;
use Illuminate\Console\Command;

class DiagnoseHistoricalRecalculationRuns extends Command
{
    protected $signature = 'historical:diagnose-runs
        {--run=* : Inspect specific run IDs}
        {--limit=100 : Maximum number of recent runs}
        {--repair : Finalize only selected non-terminal runs whose tasks are all terminal}
        {--force : Required with --repair in production}';

    protected $description = 'Diagnose historical recalculation run/task consistency without changing data by default.';

    public function handle(HistoricalRecalculationService $service): int
    {
        $runIds = collect($this->option('run'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $repair = (bool) $this->option('repair');

        if ($repair && $runIds->isEmpty()) {
            $this->error('--repair requires at least one explicit --run ID.');

            return self::FAILURE;
        }

        if ($repair && app()->isProduction() && ! $this->option('force')) {
            $this->error('--force is required with --repair in production after a verified backup.');

            return self::FAILURE;
        }

        $runs = HistoricalRecalculation::query()
            ->when($runIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $runIds))
            ->latest('id')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get();

        $rows = [];

        foreach ($runs as $run) {
            $counts = $run->tasks()
                ->selectRaw('status, COUNT(*) total')
                ->groupBy('status')
                ->pluck('total', 'status');
            $actual = [
                'pending' => (int) ($counts[HistoricalRecalculationTask::STATUS_PENDING] ?? 0),
                'running' => (int) ($counts[HistoricalRecalculationTask::STATUS_RUNNING] ?? 0),
                'completed' => (int) ($counts[HistoricalRecalculationTask::STATUS_COMPLETED] ?? 0),
                'failed' => (int) ($counts[HistoricalRecalculationTask::STATUS_FAILED] ?? 0),
                'cancelled' => (int) ($counts[HistoricalRecalculationTask::STATUS_CANCELLED] ?? 0),
            ];
            $total = array_sum($actual);
            $terminal = $actual['completed'] + $actual['failed'] + $actual['cancelled'];
            $countersMatch = $run->total_tasks === $total
                && $run->completed_tasks === $actual['completed']
                && $run->failed_tasks === $actual['failed']
                && $run->cancelled_tasks === $actual['cancelled'];
            $recommendation = $this->recommendation($run, $total, $terminal, $countersMatch);

            if ($repair && $recommendation === 'FINALIZE') {
                $service->finalize($run->id);
                $recommendation = 'FINALIZED';
                $run->refresh();
            }

            $rows[] = [
                $run->id,
                $run->dashboard_section,
                $run->status,
                $total,
                $actual['pending'],
                $actual['running'],
                $actual['completed'],
                $actual['failed'],
                $actual['cancelled'],
                $countersMatch ? 'OK' : 'MISMATCH',
                $recommendation,
            ];
        }

        $this->table([
            'Run', 'Module', 'Status', 'Total', 'Pending', 'Running', 'Completed',
            'Failed', 'Cancelled', 'Counters', 'Recommended action',
        ], $rows);

        return self::SUCCESS;
    }

    private function recommendation(
        HistoricalRecalculation $run,
        int $total,
        int $terminal,
        bool $countersMatch
    ): string {
        if (! $run->isTerminal() && $total === 0) {
            return 'REVIEW_NO_TASKS';
        }

        if (! $run->isTerminal() && $total > 0 && $terminal === $total) {
            return 'FINALIZE';
        }

        if ($run->isTerminal() && $terminal !== $total) {
            return 'REVIEW_TERMINAL_WITH_ACTIVE_TASKS';
        }

        if (! $countersMatch) {
            return 'REFRESH_COUNTERS';
        }

        return 'NONE';
    }
}
