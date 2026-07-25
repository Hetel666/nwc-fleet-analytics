<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Services\DashboardDailyAverageService;
use App\Services\DashboardFleetDrilldownService;
use App\Services\DashboardService;
use App\Services\FleetEfficiencyService;
use App\Services\GeofenceViolationService;
use App\Services\TopWorkingUnitsService;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DiagnoseDashboardPerformance extends Command
{
    protected $signature = 'fleet:diagnose-dashboard-performance
        {--from= : Start date, YYYY-MM-DD}
        {--to= : End date, YYYY-MM-DD}
        {--project= : Project ID}
        {--ownership= : NWC or ICARE}
        {--widget= : Single widget key}
        {--details : Print per-widget SQL details}
        {--explain : Run EXPLAIN for slow SELECT queries}
        {--no-cache : Mark cache as bypassed for this diagnostic run}';

    protected $description = 'Measure Dashboard widget duration, SQL, memory and result size without changing data.';

    /** @var array<int, array<string, mixed>> */
    private array $queries = [];

    private ?string $currentWidget = null;

    public function handle(
        DashboardService $dashboard,
        DashboardDailyAverageService $dailyAverages,
        FleetEfficiencyService $efficiency,
        TopWorkingUnitsService $topWorkingUnits,
        GeofenceViolationService $geofenceViolations,
        DashboardFleetDrilldownService $drilldown,
    ): int {
        DB::listen(function (QueryExecuted $event): void {
            $this->queries[] = [
                'widget' => $this->currentWidget ?? 'unknown',
                'sql' => $event->sql,
                'raw_sql' => method_exists($event, 'toRawSql') ? $event->toRawSql() : $event->sql,
                'bindings' => $this->safeBindings($event->bindings),
                'time_ms' => (float) $event->time,
            ];
        });

        $filters = $dashboard->normalizeFilters([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'project_id' => $this->option('project'),
            'ownership_type' => $this->option('ownership'),
        ]);

        $this->line('Dashboard performance diagnostic');
        $this->line('Filters: '.json_encode($filters, JSON_UNESCAPED_SLASHES));
        $this->line('Cache driver: '.(string) config('cache.default').'; mode: '.($this->option('no-cache') ? 'bypassed' : 'not cleared'));
        $this->newLine();

        $widgets = $this->widgets($dashboard, $dailyAverages, $efficiency, $topWorkingUnits, $geofenceViolations, $drilldown, $filters);
        $onlyWidget = $this->option('widget') ? (string) $this->option('widget') : null;

        if ($onlyWidget) {
            $widgets = array_filter(
                $widgets,
                fn (array $widget): bool => $widget['key'] === $onlyWidget
            );
        }

        if ($widgets === []) {
            $this->error('No widgets matched.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($widgets as $widget) {
            $results[] = $this->measure($widget);
        }

        usort($results, fn (array $first, array $second): int => $second['duration_ms'] <=> $first['duration_ms']);

        $this->table(
            ['Widget', 'Duration ms', 'Queries', 'SQL time ms', 'Rows returned', 'Peak memory MB', 'Cache', 'Status'],
            array_map(fn (array $result): array => [
                $result['key'],
                number_format($result['duration_ms'], 1, '.', ''),
                $result['queries'],
                number_format($result['sql_time_ms'], 1, '.', ''),
                $result['rows_returned'],
                number_format($result['peak_memory_mb'], 1, '.', ''),
                $result['cache'],
                $result['status'],
            ], $results)
        );

        $totalDuration = array_sum(array_column($results, 'duration_ms'));
        $totalQueries = array_sum(array_column($results, 'queries'));
        $totalSqlTime = array_sum(array_column($results, 'sql_time_ms'));
        $peakMemory = max(array_column($results, 'peak_memory_mb'));
        $slowestWidget = $results[0];
        $highestMemoryWidget = collect($results)->sortByDesc('peak_memory_mb')->first();
        $slowestQuery = collect($this->queries)->sortByDesc('time_ms')->first();

        $this->newLine();
        $this->line('Totals');
        $this->line('Total duration: '.number_format($totalDuration, 1, '.', '').' ms');
        $this->line('Total queries: '.$totalQueries);
        $this->line('Total SQL time: '.number_format($totalSqlTime, 1, '.', '').' ms');
        $this->line('Peak memory: '.number_format($peakMemory, 1, '.', '').' MB');
        $this->line('Slowest widget: '.$slowestWidget['key'].' ('.number_format($slowestWidget['duration_ms'], 1, '.', '').' ms)');
        $this->line('Highest-memory widget: '.$highestMemoryWidget['key'].' ('.number_format($highestMemoryWidget['peak_memory_mb'], 1, '.', '').' MB)');

        if ($slowestQuery) {
            $this->line('Slowest SQL query: '.$slowestQuery['widget'].' '.number_format((float) $slowestQuery['time_ms'], 1, '.', '').' ms');
            $this->line($this->shortSql((string) ($slowestQuery['raw_sql'] ?? $slowestQuery['sql'])));
        }

        if ($this->option('details')) {
            $this->printDetails($results);
        }

        if ($this->option('explain')) {
            $this->printExplain();
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function widgets(
        DashboardService $dashboard,
        DashboardDailyAverageService $dailyAverages,
        FleetEfficiencyService $efficiency,
        TopWorkingUnitsService $topWorkingUnits,
        GeofenceViolationService $geofenceViolations,
        DashboardFleetDrilldownService $drilldown,
        array $filters,
    ): array {
        $drilldownFilters = $drilldown->filters([
            'date_from' => $filters['from'],
            'date_to' => $filters['to'],
            'project_id' => $filters['project_id'],
            'ownership' => $filters['ownership_type'],
            'per_page' => 50,
        ]);

        return [
            [
                'key' => 'overview-kpi',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getOverview($filters),
            ],
            [
                'key' => 'work-hour-categories',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getWorkHourCategories($filters),
            ],
            [
                'key' => 'equipment-types',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getEquipmentTypeDistribution($filters),
            ],
            [
                'key' => 'equipment-types-by-ownership',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getEquipmentTypeDistributionByOwnership([...$filters, '_include_type_id' => true]),
            ],
            [
                'key' => 'average-metrics',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getAverageMetrics($filters),
            ],
            [
                'key' => 'daily-average-engine-hours',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dailyAverages->dashboardData($filters, 'engine_hours'),
            ],
            [
                'key' => 'daily-average-mileage',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dailyAverages->dashboardData($filters, 'mileage'),
            ],
            [
                'key' => 'efficiency-project-rows',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $efficiency->projectRowsByOwnership($filters),
            ],
            [
                'key' => 'efficiency-nwc-summary',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $efficiency->summaryForOwnership($filters, Equipment::OWNERSHIP_NWC),
            ],
            [
                'key' => 'efficiency-icare-summary',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $efficiency->summaryForOwnership($filters, Equipment::OWNERSHIP_ICARE),
            ],
            [
                'key' => 'top20-least-working',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $topWorkingUnits->least($filters, 20),
            ],
            [
                'key' => 'top20-most-working',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $topWorkingUnits->most($filters, 20),
            ],
            [
                'key' => 'project-distribution',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getProjectDistribution($filters),
            ],
            [
                'key' => 'project-ownership-comparison',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getProjectOwnershipComparison($filters),
            ],
            [
                'key' => 'geofence-violations',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $geofenceViolations->summary($filters),
            ],
            [
                'key' => 'utilization-trend',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getUtilizationTrend($filters),
            ],
            [
                'key' => 'utilization-trend-by-ownership',
                'cache' => $this->cacheLabel(),
                'callback' => fn (): array => $dashboard->getUtilizationTrendByOwnership($filters),
            ],
            [
                'key' => 'modal-equipment-list',
                'cache' => 'none',
                'callback' => fn (): array => $this->paginatorPayload($drilldown->getUnits($drilldownFilters)),
            ],
            [
                'key' => 'modal-efficiency-nwc-less-than-1',
                'cache' => 'none',
                'callback' => fn (): array => $this->paginatorPayload($drilldown->getUnits($drilldown->filters([
                    'date_from' => $filters['from'],
                    'date_to' => $filters['to'],
                    'project_id' => $filters['project_id'],
                    'ownership' => Equipment::OWNERSHIP_NWC,
                    'work_category' => FleetEfficiencyService::DAY_STATUS_LESS_THAN_1,
                    'per_page' => 50,
                ]))),
            ],
            [
                'key' => 'excel-average-engine-hours',
                'cache' => 'none',
                'callback' => fn (): array => $dashboard->getDashboardExport($filters, 'average-engine-hours'),
            ],
            [
                'key' => 'excel-geofence-analysis',
                'cache' => 'none',
                'callback' => fn (): array => $dashboard->getDashboardExport($filters, 'geofence-analysis'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $widget
     * @return array<string, mixed>
     */
    private function measure(array $widget): array
    {
        gc_collect_cycles();

        $this->currentWidget = $widget['key'];
        $queryStart = count($this->queries);
        $memoryBefore = memory_get_usage(true);
        $startedAt = hrtime(true);
        $status = 'ok';
        $result = null;

        try {
            $result = ($widget['callback'])();
        } catch (Throwable $exception) {
            $status = 'error: '.$exception::class.' '.$exception->getMessage();
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $queries = array_slice($this->queries, $queryStart);
        $memoryAfter = memory_get_usage(true);
        $this->currentWidget = null;

        return [
            'key' => $widget['key'],
            'duration_ms' => $durationMs,
            'queries' => count($queries),
            'sql_time_ms' => array_sum(array_column($queries, 'time_ms')),
            'rows_returned' => $this->estimateRows($result),
            'peak_memory_mb' => $this->bytesToMb(max($memoryAfter, memory_get_peak_usage(true), $memoryBefore)),
            'memory_delta_mb' => $this->bytesToMb($memoryAfter - $memoryBefore),
            'result_size_kb' => $this->resultSizeKb($result),
            'cache' => (string) $widget['cache'],
            'status' => $status,
            'queries_detail' => $queries,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function printDetails(array $results): void
    {
        $this->newLine();
        $this->line('Details');

        foreach ($results as $result) {
            $this->line(sprintf(
                '%s: memory_delta=%s MB, result_size=%s KB',
                $result['key'],
                number_format((float) $result['memory_delta_mb'], 1, '.', ''),
                number_format((float) $result['result_size_kb'], 1, '.', '')
            ));

            $slowQueries = collect($result['queries_detail'])
                ->sortByDesc('time_ms')
                ->take(5);

            foreach ($slowQueries as $query) {
                $this->line('  SQL '.$query['time_ms'].' ms: '.$this->shortSql((string) ($query['raw_sql'] ?? $query['sql'])));
            }
        }
    }

    private function printExplain(): void
    {
        $this->newLine();
        $this->line('EXPLAIN for slow SELECT queries');

        collect($this->queries)
            ->filter(fn (array $query): bool => (float) $query['time_ms'] >= 100.0)
            ->filter(fn (array $query): bool => str_starts_with(ltrim(strtolower((string) ($query['raw_sql'] ?? $query['sql']))), 'select'))
            ->sortByDesc('time_ms')
            ->take(5)
            ->each(function (array $query): void {
                $sql = (string) ($query['raw_sql'] ?? $query['sql']);
                $this->line($query['widget'].' '.$query['time_ms'].' ms');
                $this->line($this->shortSql($sql, 240));

                try {
                    $rows = DB::select('EXPLAIN '.$sql);
                    $this->table(array_keys((array) ($rows[0] ?? [])), array_map(fn ($row): array => (array) $row, $rows));
                } catch (Throwable $exception) {
                    $this->warn('EXPLAIN failed: '.$exception->getMessage());
                }
            });
    }

    private function cacheLabel(): string
    {
        if ($this->option('no-cache')) {
            return 'bypassed';
        }

        $minutes = max(0, (int) config('fleet.dashboard.cache_minutes', 10));

        return $minutes > 0 ? 'dashboard '.$minutes.'m' : 'disabled';
    }

    /**
     * @param mixed $paginator
     * @return array<string, mixed>
     */
    private function paginatorPayload(mixed $paginator): array
    {
        return [
            'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
            'items' => method_exists($paginator, 'items') ? $paginator->items() : [],
        ];
    }

    private function estimateRows(mixed $value): int
    {
        if ($value instanceof Collection) {
            return $value->count();
        }

        if (is_object($value) && method_exists($value, 'total')) {
            return (int) $value->total();
        }

        if (! is_array($value)) {
            return $value === null ? 0 : 1;
        }

        if (array_is_list($value)) {
            return count($value);
        }

        $total = 0;

        foreach ($value as $item) {
            if ($item instanceof Collection) {
                $total += $item->count();
            } elseif (is_object($item) && method_exists($item, 'total')) {
                $total += (int) $item->total();
            } elseif (is_array($item) && array_is_list($item)) {
                $total += count($item);
            }
        }

        return $total > 0 ? $total : count($value);
    }

    private function resultSizeKb(mixed $value): float
    {
        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? 0.0 : strlen($encoded) / 1024;
    }

    private function bytesToMb(int|float $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }

    /**
     * @param array<int, mixed> $bindings
     * @return array<int, mixed>
     */
    private function safeBindings(array $bindings): array
    {
        return array_map(function (mixed $binding): mixed {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }

            if (is_string($binding) && strlen($binding) > 80) {
                return substr($binding, 0, 80).'...';
            }

            return $binding;
        }, $bindings);
    }

    private function shortSql(string $sql, int $limit = 180): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?: '';

        return strlen($sql) > $limit ? substr($sql, 0, $limit - 3).'...' : $sql;
    }
}
