<?php

namespace App\Console\Commands;

use App\Models\HistoricalRecalculation;
use App\Services\DashboardReportPipelineService;
use App\Services\HistoricalRecalculationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class QueueDashboardReportsSync extends Command
{
    protected $signature = 'dashboard-reports:queue-sync
        {--daily : Queue the daily dashboard refresh for completed periods}
        {--date= : Single business date for non-night modules}
        {--from= : Start date for a historical range}
        {--to= : End date for a historical range}
        {--module=* : Dashboard module code to queue}
        {--project=* : Restrict to project IDs}
        {--force : Rebuild data where the module supports forced refresh}
        {--chunk-days=7 : Date range chunk size for historical pipelines}
        {--allow-active : Queue even when an overlapping active run already exists}
        {--dry-run : Show the queue plan without creating runs}';

    protected $description = 'Queue dashboard report recalculations without executing Wialon reports inside the scheduler.';

    /** @var array<int, string> */
    private const DAILY_MODULES = [
        HistoricalRecalculation::SECTION_DAILY_AVERAGES,
        HistoricalRecalculation::SECTION_EFFICIENCY,
        HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
        HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
        HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
        HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
    ];

    private const RANGE_MODULES = [
        HistoricalRecalculation::SECTION_DAILY_AVERAGES,
        HistoricalRecalculation::SECTION_EFFICIENCY,
        HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
        HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
        HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
        HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
        HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
    ];

    /** @var array<string, string> */
    private const MODULE_ALIASES = [
        'average_engine_hours' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
        'average_mileage' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
        'daily_averages' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
        'efficiency' => HistoricalRecalculation::SECTION_EFFICIENCY,
        'daytime_efficiency' => HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
        'nighttime_efficiency' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
        'top_20' => HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
        'top20' => HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
        'top_working_units' => HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
        'geofence_violations' => HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
        'geofence_transfers' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
        'geofence_outside' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
    ];

    public function handle(HistoricalRecalculationService $service, DashboardReportPipelineService $pipelines): int
    {
        $timezone = (string) config('historical_recalculation.timezone', config('app.timezone', 'Asia/Baku'));
        $plans = $this->plans($timezone);
        $rows = [];
        $queuePlans = collect();

        if ($plans->isEmpty()) {
            $this->warn('No dashboard report runs were planned.');

            return self::SUCCESS;
        }

        foreach ($plans as $plan) {
            $preview = null;
            $decision = $this->option('dry-run') ? 'dry-run' : 'pipeline pending';

            try {
                $preview = $service->preview($this->payload($plan));
            } catch (Throwable $exception) {
                $rows[] = $this->row($plan, null, 'invalid: '.$exception->getMessage(), null);

                continue;
            }

            if (! $this->option('allow-active') && $this->hasOverlappingActiveRun($plan)) {
                $rows[] = $this->row($plan, $preview, 'skipped: overlapping active run', null);

                continue;
            }

            if ($queuePlans->isEmpty() && ! $this->option('dry-run')) {
                $decision = 'pipeline first step';
            }

            $rows[] = $this->row($plan, $preview, $decision, null);
            $queuePlans->push($plan);
        }

        $result = null;

        if (! $this->option('dry-run') && $queuePlans->isNotEmpty()) {
            $validPlans = $queuePlans->values()->all();
            $source = $this->source($validPlans);
            $result = $pipelines->queue(
                $validPlans,
                $source,
                $pipelines->priorityForSource($source),
                (bool) $this->option('allow-active')
            );
        }

        $this->table(
            ['Module', 'Date from', 'Date to', 'Scope', 'Tasks', 'Decision', 'Run ID'],
            $rows
        );

        if ($result !== null) {
            $pipeline = $result['pipeline'] ?? null;
            $this->line(sprintf(
                'Pipeline %s: %s; started run: %s',
                is_array($pipeline) ? ($pipeline['id'] ?? '-') : '-',
                $result['status'] ?? '-',
                $result['started_run_id'] ?? '-'
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{section: string, date_from: string, date_to: string, scope: string, project_ids: array<int, int>, force: bool}>
     */
    private function plans(string $timezone): Collection
    {
        $sections = $this->sections((bool) $this->option('daily'));

        if ((bool) $this->option('daily')) {
            $businessDate = Carbon::parse(
                (string) ($this->option('date') ?: now($timezone)->subDay()->toDateString()),
                $timezone
            )->toDateString();
            $lastNightShiftDate = $this->lastCompletedNightShiftDate(now($timezone));

            return $sections->map(fn (string $section): array => $this->plan(
                $section,
                $section === HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY ? $lastNightShiftDate : $businessDate,
                $section === HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY ? $lastNightShiftDate : $businessDate
            ));
        }

        $from = $this->option('from') ?: $this->option('date');
        $to = $this->option('to') ?: $this->option('date') ?: $from;

        if (! $from || ! $to) {
            $this->error('Use --daily, --date, or both --from and --to.');

            return collect();
        }

        $from = Carbon::parse((string) $from, $timezone)->toDateString();
        $to = Carbon::parse((string) $to, $timezone)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $this->dateChunks($from, $to, $timezone)
            ->flatMap(function (array $chunk) use ($sections, $timezone): array {
                return $sections->flatMap(function (string $section) use ($chunk, $timezone): array {
                    if ($section === HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY) {
                        $lastCompletedShiftDate = $this->lastCompletedNightShiftDate(now($timezone));
                        $nightTo = min($chunk['to'], $lastCompletedShiftDate);

                        if ($chunk['from'] > $nightTo) {
                            return [];
                        }

                        return [$this->plan($section, $chunk['from'], $nightTo)];
                    }

                    return [$this->plan($section, $chunk['from'], $chunk['to'])];
                })->all();
        })->values();
    }

    /** @return Collection<int, string> */
    private function sections(bool $daily): Collection
    {
        $requested = collect($this->option('module'))
            ->map(fn (mixed $module): string => trim((string) $module))
            ->filter();

        if ($requested->isEmpty()) {
            return collect($daily ? self::DAILY_MODULES : self::RANGE_MODULES);
        }

        return $requested
            ->map(fn (string $module): ?string => self::MODULE_ALIASES[$module] ?? self::MODULE_ALIASES[strtolower($module)] ?? null)
            ->filter()
            ->unique()
            ->values();
    }

    /** @return array{section: string, date_from: string, date_to: string, scope: string, project_ids: array<int, int>, force: bool} */
    private function plan(string $section, string $from, string $to): array
    {
        $projectIds = collect($this->option('project'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'section' => $section,
            'date_from' => $from,
            'date_to' => $to,
            'scope' => $projectIds === []
                ? HistoricalRecalculation::SCOPE_ALL_PROJECTS
                : HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            'project_ids' => $projectIds,
            'force' => (bool) $this->option('force'),
        ];
    }

    /** @return Collection<int, array{from: string, to: string}> */
    private function dateChunks(string $from, string $to, string $timezone): Collection
    {
        $chunkDays = max(1, min(31, (int) $this->option('chunk-days')));
        $cursor = Carbon::parse($from, $timezone)->startOfDay();
        $end = Carbon::parse($to, $timezone)->startOfDay();
        $chunks = [];

        while ($cursor->lte($end)) {
            $chunkTo = $cursor->copy()->addDays($chunkDays - 1)->min($end);
            $chunks[] = [
                'from' => $cursor->toDateString(),
                'to' => $chunkTo->toDateString(),
            ];
            $cursor = $chunkTo->copy()->addDay();
        }

        return collect($chunks);
    }

    /** @param  array{section: string, date_from: string, date_to: string, scope: string, project_ids: array<int, int>, force: bool}  $plan */
    private function payload(array $plan): array
    {
        return [
            'date_from' => $plan['date_from'],
            'date_to' => $plan['date_to'],
            'timezone' => (string) config('historical_recalculation.timezone', config('app.timezone', 'Asia/Baku')),
            'dashboard_section' => $plan['section'],
            'operation' => HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            'scope' => $plan['scope'],
            'project_ids' => $plan['project_ids'],
            'force' => $plan['force'],
        ];
    }

    /** @param  array{section: string, date_from: string, date_to: string, scope: string, project_ids: array<int, int>, force: bool}  $plan */
    private function hasOverlappingActiveRun(array $plan): bool
    {
        return HistoricalRecalculation::query()
            ->where('dashboard_section', $plan['section'])
            ->whereIn('status', [
                HistoricalRecalculation::STATUS_PENDING,
                HistoricalRecalculation::STATUS_RUNNING,
            ])
            ->whereDate('date_from', '<=', $plan['date_to'])
            ->whereDate('date_to', '>=', $plan['date_from'])
            ->exists();
    }

    private function lastCompletedNightShiftDate(Carbon $now): string
    {
        $cutoff = $now->copy()->setTime(8, 5);

        return $now->lt($cutoff)
            ? $now->copy()->subDays(2)->toDateString()
            : $now->copy()->subDay()->toDateString();
    }

    /** @param  array<int, array{section: string, date_from: string, date_to: string, scope: string, project_ids: array<int, int>, force: bool}>  $plans */
    private function source(array $plans): string
    {
        if ((bool) $this->option('daily')) {
            return 'daily';
        }

        $dates = collect($plans)
            ->flatMap(fn (array $plan): array => [$plan['date_from'], $plan['date_to']])
            ->unique();

        return $dates->count() > 1 ? 'historical' : 'manual';
    }

    /**
     * @param  array{section: string, date_from: string, date_to: string, scope: string, project_ids: array<int, int>, force: bool}  $plan
     * @param  array{total_tasks?: int}|null  $preview
     * @return array<int, int|string|null>
     */
    private function row(array $plan, ?array $preview, string $decision, ?int $runId): array
    {
        return [
            $plan['section'],
            $plan['date_from'],
            $plan['date_to'],
            $plan['scope'],
            $preview['total_tasks'] ?? null,
            mb_substr($decision, 0, 120),
            $runId,
        ];
    }
}
