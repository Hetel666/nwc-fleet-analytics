<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Services\DashboardReportPipelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DashboardReportsStatus extends Command
{
    protected $signature = 'dashboard-reports:status
        {run? : Historical recalculation run ID}
        {--active : Show only active historical runs}
        {--limit=10 : Number of recent runs to show}';

    protected $description = 'Show read-only dashboard report queue and historical recalculation progress.';

    public function handle(DashboardReportPipelineService $pipelines): int
    {
        $this->line('Pipelines');
        $this->table(
            ['ID', 'Source', 'Priority', 'Status', 'Step', 'Total', 'Current run', 'Updated at'],
            collect($pipelines->all())->map(fn (array $pipeline): array => [
                $pipeline['id'] ?? '-',
                $pipeline['source'] ?? '-',
                (int) ($pipeline['priority'] ?? 0),
                $pipeline['status'] ?? '-',
                (int) ($pipeline['current_index'] ?? 0) + 1,
                count($pipeline['plans'] ?? []),
                $pipeline['current_run_id'] ?? '-',
                $pipeline['updated_at'] ?? '-',
            ])->all()
        );

        $this->line('Queue');
        $jobs = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as total, SUM(reserved_at IS NULL) as available, SUM(reserved_at IS NOT NULL) as reserved, MIN(id) as min_id, MAX(id) as max_id')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();
        $this->table(
            ['Queue', 'Total', 'Available', 'Reserved', 'Min ID', 'Max ID'],
            $jobs->map(fn (object $row): array => [
                $row->queue,
                (int) $row->total,
                (int) $row->available,
                (int) $row->reserved,
                $row->min_id,
                $row->max_id,
            ])->all()
        );

        $failedJobs = DB::table('failed_jobs')
            ->selectRaw('queue, COUNT(*) as total, MIN(failed_at) as first_failed_at, MAX(failed_at) as last_failed_at')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();
        $this->line('Failed jobs');
        $this->table(
            ['Queue', 'Total', 'First failed at', 'Last failed at'],
            $failedJobs->map(fn (object $row): array => [
                $row->queue,
                (int) $row->total,
                $row->first_failed_at,
                $row->last_failed_at,
            ])->all()
        );

        $runs = $this->runs();
        $this->line('Historical runs');
        $this->table(
            ['ID', 'Module', 'Status', 'From', 'To', 'Total', 'Done', 'Failed', 'Cancelled', 'Rows', 'Heartbeat'],
            $runs->map(fn (HistoricalRecalculation $run): array => [
                $run->id,
                $run->dashboard_section,
                $run->status,
                $run->date_from?->toDateString(),
                $run->date_to?->toDateString(),
                (int) $run->total_tasks,
                (int) $run->completed_tasks,
                (int) $run->failed_tasks,
                (int) $run->cancelled_tasks,
                (int) $run->processed_objects,
                $run->last_heartbeat_at?->toDateTimeString(),
            ])->all()
        );

        $runId = $this->argument('run');

        if ($runId !== null) {
            $this->showRunDetails((int) $runId);
        }

        return self::SUCCESS;
    }

    private function runs()
    {
        $query = HistoricalRecalculation::query()->latest('id');

        if ($this->argument('run') !== null) {
            $query->whereKey((int) $this->argument('run'));
        } elseif ((bool) $this->option('active')) {
            $query->whereIn('status', [
                HistoricalRecalculation::STATUS_PENDING,
                HistoricalRecalculation::STATUS_RUNNING,
            ]);
        } else {
            $query->limit(max(1, min(50, (int) $this->option('limit'))));
        }

        return $query->get();
    }

    private function showRunDetails(int $runId): void
    {
        $staleSeconds = max(0, (int) config('historical_recalculation.stale_running_task_seconds', 2400));
        $staleCutoff = now(config('app.timezone'))->subSeconds($staleSeconds);
        $counts = HistoricalRecalculationTask::query()
            ->where('historical_recalculation_id', $runId)
            ->selectRaw('status, operation, COUNT(*) as total, COALESCE(SUM(equipment_count), 0) as equipment_count')
            ->groupBy('status', 'operation')
            ->orderBy('operation')
            ->orderBy('status')
            ->get();

        $this->line('Task counts');
        $this->table(
            ['Operation', 'Status', 'Tasks', 'Rows'],
            $counts->map(fn (HistoricalRecalculationTask $row): array => [
                $row->operation,
                $row->status,
                (int) $row->total,
                (int) $row->equipment_count,
            ])->all()
        );

        $current = HistoricalRecalculationTask::query()
            ->where('historical_recalculation_id', $runId)
            ->whereIn('status', [
                HistoricalRecalculationTask::STATUS_RUNNING,
                HistoricalRecalculationTask::STATUS_PENDING,
                HistoricalRecalculationTask::STATUS_FAILED,
            ])
            ->orderByRaw("CASE status WHEN 'running' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderBy('stat_date')
            ->orderBy('project_id')
            ->orderBy('ownership_type')
            ->limit(10)
            ->get();

        $this->line('Current tasks');
        $this->table(
            ['ID', 'Operation', 'Status', 'Date', 'Project', 'Ownership', 'Attempts', 'Rows', 'Heartbeat', 'Stale', 'Error'],
            $current->map(function (HistoricalRecalculationTask $task) use ($staleCutoff): array {
                $stale = $task->status === HistoricalRecalculationTask::STATUS_RUNNING
                    && ($task->last_heartbeat_at === null || $task->last_heartbeat_at->lte($staleCutoff));

                return [
                    $task->id,
                    $task->operation,
                    $task->status,
                    $task->stat_date?->toDateString(),
                    $task->project_id,
                    $task->ownership_type,
                    (int) $task->attempts,
                    (int) $task->equipment_count,
                    $task->last_heartbeat_at?->toDateTimeString(),
                    $stale ? 'yes' : '',
                    mb_substr((string) $task->error_message, 0, 120),
                ];
            })->all()
        );
    }
}
