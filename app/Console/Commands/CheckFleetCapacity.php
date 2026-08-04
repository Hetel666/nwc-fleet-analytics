<?php

namespace App\Console\Commands;

use App\Models\DashboardExport;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class CheckFleetCapacity extends Command
{
    protected $signature = 'fleet:capacity-check
        {--disk-path= : Filesystem path used for disk capacity}
        {--export-root= : Dashboard exports root path}
        {--backup-path= : Backup directory path}
        {--docker-logs-path= : Docker containers log directory path}
        {--table-limit= : Number of largest database tables to show}
        {--json : Print machine-readable JSON}
        {--no-alert : Do not emit threshold alerts}';

    protected $description = 'Read-only capacity audit for Fleet Analytics storage, logs, exports, backups, queues, and database tables.';

    public function handle(): int
    {
        $report = $this->buildReport();

        if (! (bool) $this->option('no-alert')) {
            $this->emitAlerts($report);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildReport(): array
    {
        $diskPath = (string) ($this->option('disk-path') ?: config('capacity.paths.disk', storage_path()));
        $exportRoot = (string) ($this->option('export-root') ?: config('filesystems.disks.dashboard_exports.root'));
        $backupPath = (string) ($this->option('backup-path') ?: config('capacity.paths.backups'));
        $dockerLogsPath = (string) ($this->option('docker-logs-path') ?: config('capacity.paths.docker_logs'));
        $tableLimit = max(1, (int) ($this->option('table-limit') ?: config('capacity.database.table_limit', 15)));

        return [
            'checked_at' => now(config('app.timezone'))->toDateTimeString(),
            'timezone' => config('app.timezone'),
            'thresholds' => $this->thresholds(),
            'disk' => $this->diskSummary($diskPath),
            'directories' => [
                'laravel_logs' => $this->directorySummary(storage_path('logs'), ['*.log']),
                'dashboard_exports' => $this->dashboardExportSummary($exportRoot),
                'backups' => $this->directorySummary($backupPath),
                'docker_logs' => $this->directorySummary($dockerLogsPath, ['*-json.log', '*.log']),
            ],
            'database' => [
                'tables' => $this->tableSizes($tableLimit),
                'total_bytes' => $this->databaseTotalBytes(),
                'max_bytes' => (int) config('capacity.database.max_bytes', 0),
                'usage_percent' => $this->percent($this->databaseTotalBytes(), (int) config('capacity.database.max_bytes', 0)),
            ],
            'queues' => $this->queueSummary(),
        ];
    }

    /** @return array<string, mixed> */
    private function diskSummary(string $path): array
    {
        $resolved = $this->existingPath($path);

        if ($resolved === null) {
            return [
                'path' => $path,
                'status' => 'missing',
            ];
        }

        $total = (int) @disk_total_space($resolved);
        $free = (int) @disk_free_space($resolved);
        $used = max(0, $total - $free);

        return [
            'path' => $resolved,
            'status' => $total > 0 ? 'ok' : 'unavailable',
            'total_bytes' => $total,
            'free_bytes' => $free,
            'used_bytes' => $used,
            'used_percent' => $this->percent($used, $total),
        ];
    }

    /** @return array<string, mixed> */
    private function dashboardExportSummary(string $root): array
    {
        $summary = $this->directorySummary($root);
        $summary['max_files'] = (int) config('capacity.dashboard_exports.max_files', 0);
        $summary['max_bytes'] = (int) config('capacity.dashboard_exports.max_bytes', 0);
        $summary['usage_percent'] = $this->percent((int) ($summary['bytes'] ?? 0), (int) $summary['max_bytes']);

        if ($this->tableExists('dashboard_exports')) {
            $summary['records'] = DashboardExport::query()->count();
            $summary['ready_records'] = DashboardExport::query()->where('status', DashboardExport::STATUS_READY)->count();
            $summary['pending_records'] = DashboardExport::query()->whereIn('status', [
                DashboardExport::STATUS_PENDING,
                DashboardExport::STATUS_PROCESSING,
            ])->count();
            $summary['expired_records'] = DashboardExport::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now(config('app.timezone')))
                ->count();
        }

        return $summary;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<string, mixed>
     */
    private function directorySummary(string $path, array $patterns = ['*']): array
    {
        if (! is_dir($path)) {
            return [
                'path' => $path,
                'status' => 'missing',
                'files' => 0,
                'bytes' => 0,
            ];
        }

        $files = 0;
        $bytes = 0;
        $oldest = null;
        $newest = null;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! $this->matchesAnyPattern($file->getFilename(), $patterns)) {
                    continue;
                }

                $files++;
                $bytes += (int) $file->getSize();
                $mtime = (int) $file->getMTime();
                $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
                $newest = $newest === null ? $mtime : max($newest, $mtime);
            }
        } catch (Throwable $exception) {
            return [
                'path' => $path,
                'status' => 'unreadable',
                'error' => $exception->getMessage(),
                'files' => $files,
                'bytes' => $bytes,
            ];
        }

        return [
            'path' => $path,
            'status' => 'ok',
            'files' => $files,
            'bytes' => $bytes,
            'oldest_modified_at' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
            'newest_modified_at' => $newest ? date('Y-m-d H:i:s', $newest) : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tableSizes(int $limit): array
    {
        try {
            return match (DB::getDriverName()) {
                'mysql' => collect(DB::select(
                    'select table_name as name, table_rows as rows_count, coalesce(data_length, 0) + coalesce(index_length, 0) as bytes
                     from information_schema.tables
                     where table_schema = database()
                     order by bytes desc, table_name asc
                     limit '.$limit
                ))->map(fn (object $row): array => [
                    'table' => (string) $row->name,
                    'rows' => (int) $row->rows_count,
                    'bytes' => (int) $row->bytes,
                ])->all(),
                'sqlite' => $this->sqliteTableSizes($limit),
                default => $this->knownTableCounts($limit),
            };
        } catch (Throwable) {
            return $this->knownTableCounts($limit);
        }
    }

    private function databaseTotalBytes(): int
    {
        try {
            if (DB::getDriverName() === 'mysql') {
                $row = DB::selectOne(
                    'select sum(coalesce(data_length, 0) + coalesce(index_length, 0)) as bytes
                     from information_schema.tables
                     where table_schema = database()'
                );

                return (int) ($row->bytes ?? 0);
            }

            if (DB::getDriverName() === 'sqlite') {
                $pageCount = (int) (DB::selectOne('pragma page_count')->page_count ?? 0);
                $pageSize = (int) (DB::selectOne('pragma page_size')->page_size ?? 0);

                return $pageCount * $pageSize;
            }
        } catch (Throwable) {
            return 0;
        }

        return 0;
    }

    /** @return array<int, array<string, mixed>> */
    private function sqliteTableSizes(int $limit): array
    {
        return collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            ->map(fn (object $row): string => (string) $row->name)
            ->map(fn (string $table): array => [
                'table' => $table,
                'rows' => $this->tableCount($table),
                'bytes' => null,
            ])
            ->sortByDesc('rows')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function knownTableCounts(int $limit): array
    {
        return collect([
            'equipment_daily_stats',
            'historical_recalculations',
            'historical_recalculation_tasks',
            'dashboard_exports',
            'jobs',
            'failed_jobs',
            'sessions',
            'cache',
            'cache_locks',
            'efficiency_daily_facts',
            'daytime_efficiency_daily_facts',
            'nighttime_efficiency_daily_facts',
            'night_day_efficiency_daily_facts',
            'geofence_violation_report_rows',
            'unit_foreign_geofence_intervals',
        ])
            ->filter(fn (string $table): bool => $this->tableExists($table))
            ->map(fn (string $table): array => [
                'table' => $table,
                'rows' => $this->tableCount($table),
                'bytes' => null,
            ])
            ->sortByDesc('rows')
            ->take($limit)
            ->values()
            ->all();
    }

    private function tableCount(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function queueSummary(): array
    {
        $summary = [
            'jobs' => 0,
            'failed_jobs' => 0,
            'oldest_job_age_seconds' => null,
            'active_historical_runs' => 0,
            'pending_historical_tasks' => 0,
            'running_historical_tasks' => 0,
        ];

        if ($this->tableExists('jobs')) {
            $summary['jobs'] = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $summary['oldest_job_age_seconds'] = $oldest
                ? max(0, time() - (int) $oldest)
                : null;
        }

        if ($this->tableExists('failed_jobs')) {
            $summary['failed_jobs'] = DB::table('failed_jobs')->count();
        }

        if ($this->tableExists('historical_recalculations')) {
            $summary['active_historical_runs'] = HistoricalRecalculation::query()
                ->whereIn('status', [HistoricalRecalculation::STATUS_PENDING, HistoricalRecalculation::STATUS_RUNNING])
                ->count();
        }

        if ($this->tableExists('historical_recalculation_tasks')) {
            $summary['pending_historical_tasks'] = HistoricalRecalculationTask::query()
                ->where('status', HistoricalRecalculationTask::STATUS_PENDING)
                ->count();
            $summary['running_historical_tasks'] = HistoricalRecalculationTask::query()
                ->where('status', HistoricalRecalculationTask::STATUS_RUNNING)
                ->count();
        }

        return $summary;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $report */
    private function emitAlerts(array $report): void
    {
        if (! (bool) config('capacity.alerts.enabled', true)) {
            return;
        }

        foreach ($this->alertCandidates($report) as $candidate) {
            $threshold = $this->crossedThreshold((float) $candidate['percent']);

            if ($threshold === null) {
                continue;
            }

            $cacheKey = 'fleet-capacity-alert:'.sha1($candidate['key'].':'.$threshold);

            if (Cache::has($cacheKey)) {
                continue;
            }

            $payload = [
                'metric' => $candidate['key'],
                'label' => $candidate['label'],
                'percent' => round((float) $candidate['percent'], 2),
                'threshold' => $threshold,
                'checked_at' => $report['checked_at'],
            ];

            $channel = config('capacity.alerts.log_channel');
            if ($channel) {
                Log::channel((string) $channel)->warning('Fleet capacity threshold crossed.', $payload);
            } else {
                Log::warning('Fleet capacity threshold crossed.', $payload);
            }

            $this->sendWebhookAlert($payload);

            Cache::put($cacheKey, true, now()->addMinutes((int) config('capacity.alerts.cooldown_minutes', 60)));
        }
    }

    /** @param  array<string, mixed>  $report */
    private function alertCandidates(array $report): array
    {
        return collect([
            [
                'key' => 'disk',
                'label' => 'Disk usage',
                'percent' => $report['disk']['used_percent'] ?? null,
            ],
            [
                'key' => 'dashboard_exports',
                'label' => 'Dashboard exports quota',
                'percent' => $report['directories']['dashboard_exports']['usage_percent'] ?? null,
            ],
            [
                'key' => 'database',
                'label' => 'Database quota',
                'percent' => $report['database']['usage_percent'] ?? null,
            ],
        ])
            ->filter(fn (array $candidate): bool => is_numeric($candidate['percent']))
            ->values()
            ->all();
    }

    private function crossedThreshold(float $percent): ?int
    {
        return collect($this->thresholds())
            ->filter(fn (int $threshold): bool => $percent >= $threshold)
            ->max();
    }

    /** @param  array<string, mixed>  $payload */
    private function sendWebhookAlert(array $payload): void
    {
        $url = config('capacity.alerts.webhook_url');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'text' => sprintf(
                    '%s: %.2f%% >= %d%%',
                    $payload['label'],
                    $payload['percent'],
                    $payload['threshold']
                ),
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Fleet capacity webhook alert failed.', ['message' => $exception->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $report */
    private function renderReport(array $report): void
    {
        $this->info('Fleet capacity check: '.$report['checked_at'].' '.$report['timezone']);
        $this->table(['Metric', 'Status', 'Files', 'Size', 'Usage'], [
            ['Disk', $report['disk']['status'] ?? 'unknown', '-', $this->bytes((int) ($report['disk']['used_bytes'] ?? 0)).' / '.$this->bytes((int) ($report['disk']['total_bytes'] ?? 0)), ($report['disk']['used_percent'] ?? '-').'%'],
            ['Laravel logs', $report['directories']['laravel_logs']['status'] ?? 'unknown', $report['directories']['laravel_logs']['files'] ?? 0, $this->bytes((int) ($report['directories']['laravel_logs']['bytes'] ?? 0)), '-'],
            ['Dashboard exports', $report['directories']['dashboard_exports']['status'] ?? 'unknown', $report['directories']['dashboard_exports']['files'] ?? 0, $this->bytes((int) ($report['directories']['dashboard_exports']['bytes'] ?? 0)), ($report['directories']['dashboard_exports']['usage_percent'] ?? '-').'%'],
            ['Backups', $report['directories']['backups']['status'] ?? 'unknown', $report['directories']['backups']['files'] ?? 0, $this->bytes((int) ($report['directories']['backups']['bytes'] ?? 0)), '-'],
            ['Docker logs', $report['directories']['docker_logs']['status'] ?? 'unknown', $report['directories']['docker_logs']['files'] ?? 0, $this->bytes((int) ($report['directories']['docker_logs']['bytes'] ?? 0)), '-'],
            ['Database', 'ok', '-', $this->bytes((int) ($report['database']['total_bytes'] ?? 0)), ($report['database']['usage_percent'] ?? '-').'%'],
        ]);
        $this->table(['Table', 'Rows', 'Size'], collect($report['database']['tables'] ?? [])->map(fn (array $table): array => [
            $table['table'],
            $table['rows'],
            $table['bytes'] === null ? '-' : $this->bytes((int) $table['bytes']),
        ])->all());
        $this->table(['Queue metric', 'Value'], collect($report['queues'])->map(fn (mixed $value, string $key): array => [$key, $value ?? '-'])->all());
    }

    private function percent(int $used, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round(($used / $total) * 100, 2);
    }

    private function existingPath(string $path): ?string
    {
        if (is_dir($path)) {
            return $path;
        }

        $parent = dirname($path);

        return is_dir($parent) ? $parent : null;
    }

    /** @param  array<int, string>  $patterns */
    private function matchesAnyPattern(string $filename, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> */
    private function thresholds(): array
    {
        return collect(config('capacity.alerts.thresholds', [70, 80, 85, 90]))
            ->map(fn (mixed $threshold): int => (int) $threshold)
            ->filter(fn (int $threshold): bool => $threshold > 0 && $threshold <= 100)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function bytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return number_format($bytes, $unit === 'B' ? 0 : 2).' '.$unit;
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}
