<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Services\DashboardModuleRegistry;
use App\Services\DashboardResyncDryRunPlanner;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DashboardResyncDryRun extends Command
{
    protected $signature = 'dashboard-resync:dry-run
        {dashboard_code : Dashboard module code from config/dashboard_modules.php}
        {--from= : Start date, YYYY-MM-DD}
        {--to= : End date, YYYY-MM-DD}
        {--project=* : Optional project id filter}
        {--force : Show force-mode replacement warnings}';

    protected $description = 'Show a read-only Dashboard resync impact plan without queueing jobs or changing data.';

    public function handle(DashboardModuleRegistry $modules, DashboardResyncDryRunPlanner $planner): int
    {
        $dashboardCode = (string) $this->argument('dashboard_code');
        $from = (string) ($this->option('from') ?: now(config('app.timezone'))->toDateString());
        $to = (string) ($this->option('to') ?: $from);
        $projectIds = collect($this->option('project') ?: [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $module = $modules->get($dashboardCode);
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $payload = [
            'dashboard_code' => $dashboardCode,
            'dashboard_section' => $module['dashboard_section'] ?? $dashboardCode,
            'date_from' => $from,
            'date_to' => $to,
            'scope' => $projectIds === []
                ? HistoricalRecalculation::SCOPE_ALL_PROJECTS
                : HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => $projectIds,
            'force' => (bool) $this->option('force'),
        ];

        $plan = $planner->plan($payload, [
            'days' => $days,
            'project_groups' => 0,
            'fetch_tasks' => 0,
            'aggregate_tasks' => 0,
            'total_tasks' => 0,
        ]);

        $this->info($plan['title'].' ['.$plan['dashboard_code'].']');
        $this->line('Period: '.$plan['date_from'].' - '.$plan['date_to']);
        $this->line('Isolation: '.$plan['isolation'].($plan['writes_shared_tables'] ? ' / shared write' : ' / isolated write'));
        $this->line('Report: '.$plan['source_report']);
        $this->line('Command: '.$plan['manual_command']);
        $this->newLine();
        $this->table(
            ['Table', 'Scope', 'Rows', 'Filters', 'Note'],
            collect($plan['tables'])->map(fn (array $table): array => [
                $table['table'],
                $table['shared'] ? 'shared' : 'isolated',
                $table['existing_rows'] ?? '-',
                implode('; ', $table['filters'] ?? []) ?: '-',
                ($table['note'] ?? '') ?: '-',
            ])->all()
        );

        if ($plan['warnings'] !== []) {
            $this->warn('Warnings:');
            foreach ($plan['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }

        return self::SUCCESS;
    }
}
