<?php

namespace App\Services;

use App\Models\DailyUnitAggregate;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\Setting;
use App\Models\UnitForeignGeofenceInterval;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Predis\Client;
use Throwable;

class OperationsDiagnosticService
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return array<string, mixed>
     */
    public function systemHealth(): array
    {
        return $this->report('SYSTEM HEALTH', [
            $this->phpVersion(),
            $this->laravelVersion(),
            $this->composerAutoload(),
            ...$this->extensionChecks(),
            $this->databaseConnection(),
            $this->redisConfiguration(),
            $this->queueConfiguration(),
            $this->cacheConfiguration(),
            $this->storageWritable(),
            $this->permissions(),
            $this->configCache(),
            $this->routeCache(),
            $this->viewCache(),
            $this->scheduleRegistration(),
            $this->scheduleMetadata(),
            $this->failedJobs(),
            $this->queueWorkerVisibility(),
            $this->diskSpace(),
            $this->timezone(),
            $this->appEnvironment(),
            $this->appDebug(),
            $this->memoryLimit(),
            $this->executionTime(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        return $this->report('DEPLOYMENT READINESS', [
            $this->databaseConnection(),
            $this->migrationStatus(),
            $this->pendingMigrations(),
            $this->expectedIndexes(),
            $this->duplicateIndexes(),
            $this->tableSizes(),
            $this->queueConfiguration(),
            $this->queueWorkerVisibility(),
            $this->scheduleRegistration(),
            $this->scheduleMetadata(),
            $this->dashboardDataVersion(),
            $this->dashboardSyncFlag(),
            $this->dashboardSyncStatus(),
            $this->geofenceMonitoringFlag(),
            $this->geofenceMonitoringSchedule(),
            $this->dashboardCache(),
            $this->lastSuccessfulSync(),
            $this->lastSuccessfulMonitoring(),
            $this->environmentKeys(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardDoctor(): array
    {
        return $this->report('DASHBOARD DOCTOR', [
            $this->tableCount('equipments', Equipment::class, minimumRows: 1),
            $this->tableCount('equipment_daily_stats', EquipmentDailyStat::class, minimumRows: 1),
            $this->tableCount('daily_unit_aggregates', DailyUnitAggregate::class, minimumRows: 1),
            $this->dashboardDataVersion(),
            $this->dashboardCache(),
            $this->ownershipData(),
            $this->top20Data(),
            $this->averageWidgetData(),
            $this->geofenceWidgetData(),
            $this->layoutConfiguration(),
            $this->routeExists('dashboard.drilldown.units', 'Drilldown route'),
            $this->routeExists('dashboard.drilldown.units.export', 'Drilldown export route'),
            $this->routeExists('dashboard.export', 'Dashboard export route'),
            $this->routeExists('dashboard.ownership.export', 'Ownership export route'),
            $this->routeExists('dashboard.top-working-units.export', 'Top working export route'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function fleetDoctor(): array
    {
        return $this->report('FLEET DOCTOR', [
            $this->commandExists('fleet:sync-units', 'Fleet sync command'),
            $this->commandExists('fleet:auto-sync', 'Fleet auto sync command'),
            $this->commandExists('fleet:sync-report-stats', 'Dashboard report sync command'),
            $this->fleetEquipment(),
            $this->lastUnitSync(),
            $this->storedPositions(),
            $this->positionTimestamps(),
            $this->positionFreshness(),
            $this->projectMapping(),
            $this->groupMapping(),
            $this->dashboardSyncStatus(),
            $this->aggregateBackfillStatus(),
            $this->scheduleRegistration(),
            $this->scheduleMetadata(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function geofenceDoctor(): array
    {
        return $this->report('GEOFENCE DOCTOR', [
            $this->geofenceMonitoringFlag(),
            $this->geofenceMonitoringSchedule(),
            $this->tableCount('unit_foreign_geofence_intervals', UnitForeignGeofenceInterval::class),
            $this->currentIntervals(),
            $this->openIntervals(),
            $this->duplicateOpenIntervals(),
            $this->invalidIntervals(),
            $this->staleIntervals(),
            $this->missingIntervalTimestamps(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function selfTest(): array
    {
        $reports = [
            $this->systemHealth(),
            $this->readiness(),
            $this->dashboardDoctor(),
            $this->fleetDoctor(),
            $this->geofenceDoctor(),
        ];

        $checks = collect($reports)
            ->flatMap(fn (array $report): array => collect($report['checks'])
                ->map(fn (array $check): array => [
                    ...$check,
                    'section' => $report['title'],
                    'label' => $report['title'].' / '.$check['label'],
                ])
                ->all())
            ->values()
            ->all();

        return $this->report('SYSTEM SELF TEST', $checks);
    }

    public function exitCode(array $report): int
    {
        if ($report['status'] === self::FAIL) {
            return 2;
        }

        if ($report['status'] === self::WARN) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function report(string $title, array $checks): array
    {
        $status = self::OK;

        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                $status = self::FAIL;
                break;
            }

            if ($check['status'] === self::WARN) {
                $status = self::WARN;
            }
        }

        return [
            'title' => $title,
            'status' => $status,
            'generated_at' => now(config('app.timezone'))->toIso8601String(),
            'checks' => array_values($checks),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function check(string $key, string $label, string $status, string $message, array $context = []): array
    {
        $message = $this->redactString($message);
        $context = $this->redactContext($context);

        return compact('key', 'label', 'status', 'message', 'context');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        return collect($context)
            ->map(fn (mixed $value, string $key): mixed => $this->redactValue($key, $value))
            ->all();
    }

    private function redactValue(string $key, mixed $value): mixed
    {
        if ($this->isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item, string|int $itemKey): mixed => $this->redactValue((string) $itemKey, $item))
                ->all();
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/password|passwd|pwd|token|secret|app_key|authorization|cookie|dsn/i', $key) === 1;
    }

    private function redactString(string $value): string
    {
        $patterns = [
            '/(password|passwd|pwd|token|secret|app_key|authorization|cookie)(\s*[=:]\s*)([^&\s,;]+)/i',
            '/(mysql|pgsql|redis):\/\/([^:\s]+):([^@\s]+)@/i',
        ];

        $replacements = [
            '$1$2[redacted]',
            '$1://$2:[redacted]@',
        ];

        return (string) preg_replace($patterns, $replacements, $value);
    }

    private function phpVersion(): array
    {
        return $this->check(
            'php.version',
            'PHP',
            version_compare(PHP_VERSION, '8.2.0', '>=') ? self::OK : self::FAIL,
            PHP_VERSION,
        );
    }

    private function laravelVersion(): array
    {
        return $this->check('laravel.version', 'Laravel', self::OK, app()->version());
    }

    private function composerAutoload(): array
    {
        return $this->check(
            'composer.autoload',
            'Composer',
            class_exists(InstalledVersions::class) ? self::OK : self::WARN,
            class_exists(InstalledVersions::class) ? 'Composer autoload metadata is available.' : 'Composer CLI/autoload metadata was not detected.',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extensionChecks(): array
    {
        $required = ['pdo', 'mbstring', 'openssl', 'json', 'ctype', 'tokenizer', 'xml', 'fileinfo'];
        $connection = (string) config('database.default');
        $required[] = $connection === 'mysql' ? 'pdo_mysql' : ($connection === 'sqlite' ? 'pdo_sqlite' : 'pdo_'.$connection);

        return collect(array_unique($required))
            ->map(fn (string $extension): array => $this->check(
                'extension.'.$extension,
                'Extension '.$extension,
                extension_loaded($extension) ? self::OK : self::FAIL,
                extension_loaded($extension) ? 'Loaded.' : 'Missing PHP extension.',
            ))
            ->all();
    }

    private function databaseConnection(): array
    {
        try {
            DB::select('select 1');

            return $this->check('database.connection', 'Database', self::OK, 'Connection works.', [
                'connection' => config('database.default'),
            ]);
        } catch (Throwable $exception) {
            return $this->check('database.connection', 'Database', self::FAIL, $exception->getMessage(), [
                'connection' => config('database.default'),
            ]);
        }
    }

    private function redisConfiguration(): array
    {
        $usesRedis = in_array(config('cache.default'), ['redis'], true)
            || in_array(config('queue.default'), ['redis'], true)
            || in_array(config('session.driver'), ['redis'], true);

        if (! $usesRedis) {
            return $this->check('redis.configuration', 'Redis', self::OK, 'Redis is not required by current cache/queue/session configuration.');
        }

        return $this->check(
            'redis.configuration',
            'Redis',
            extension_loaded('redis') || class_exists(Client::class) ? self::OK : self::FAIL,
            'Redis-backed driver is configured.',
        );
    }

    private function queueConfiguration(): array
    {
        $connection = (string) config('queue.default');

        return $this->check(
            'queue.connection',
            'Queue',
            filled($connection) ? self::OK : self::FAIL,
            $connection !== '' ? "Queue connection: {$connection}." : 'Queue connection is missing.',
        );
    }

    private function queueWorkerVisibility(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return $this->check('queue.workers', 'Queue Workers', self::OK, 'Sync queue does not require worker processes.');
        }

        return $this->check(
            'queue.workers',
            'Queue Workers',
            self::WARN,
            'Worker process state is not visible from Artisan diagnostics; verify process manager on the server.',
            ['connection' => $connection],
        );
    }

    private function cacheConfiguration(): array
    {
        try {
            Cache::get('operations:diagnostics:read-only');

            return $this->check('cache.connection', 'Cache', self::OK, 'Cache store is readable.', [
                'store' => config('cache.default'),
            ]);
        } catch (Throwable $exception) {
            return $this->check('cache.connection', 'Cache', self::FAIL, $exception->getMessage(), [
                'store' => config('cache.default'),
            ]);
        }
    }

    private function storageWritable(): array
    {
        return $this->writablePath('storage', storage_path(), 'Storage');
    }

    private function permissions(): array
    {
        return $this->writablePath('bootstrap.cache', base_path('bootstrap/cache'), 'Permissions');
    }

    private function writablePath(string $key, string $path, string $label): array
    {
        return $this->check(
            $key.'.writable',
            $label,
            is_dir($path) && is_writable($path) ? self::OK : self::FAIL,
            is_dir($path) && is_writable($path) ? "{$path} is writable." : "{$path} is not writable.",
        );
    }

    private function configCache(): array
    {
        return $this->cacheBuildCheck('config.cache', 'Config Cache', app()->configurationIsCached());
    }

    private function routeCache(): array
    {
        return $this->cacheBuildCheck('route.cache', 'Route Cache', app()->routesAreCached());
    }

    private function viewCache(): array
    {
        $path = storage_path('framework/views');

        return $this->check(
            'view.cache',
            'View Cache',
            is_dir($path) && is_writable($path) ? self::OK : self::FAIL,
            is_dir($path) && is_writable($path) ? 'Compiled view directory is writable.' : 'Compiled view directory is not writable.',
        );
    }

    private function cacheBuildCheck(string $key, string $label, bool $cached): array
    {
        $production = app()->environment('production');

        return $this->check(
            $key,
            $label,
            $cached ? self::OK : ($production ? self::WARN : self::OK),
            $cached ? "{$label} is built." : "{$label} is not built.",
        );
    }

    private function scheduleRegistration(): array
    {
        $events = $this->scheduleEvents();

        return $this->check(
            'schedule.registration',
            'Scheduler',
            count($events) > 0 ? self::OK : self::WARN,
            count($events) > 0 ? count($events).' scheduled command(s) registered.' : 'No scheduled commands were found.',
            ['commands' => $events],
        );
    }

    private function scheduleMetadata(): array
    {
        try {
            $events = collect(app(Schedule::class)->events())
                ->map(function ($event): array {
                    $nextRun = null;

                    if (method_exists($event, 'nextRunDate')) {
                        try {
                            $nextRun = $event->nextRunDate(now(config('app.timezone')))?->toDateTimeString();
                        } catch (Throwable) {
                            $nextRun = null;
                        }
                    }

                    return [
                        'command' => trim((string) $event->command),
                        'expression' => (string) ($event->expression ?? ''),
                        'without_overlapping' => (bool) ($event->withoutOverlapping ?? false),
                        'next_run_at' => $nextRun,
                    ];
                })
                ->filter(fn (array $event): bool => $event['command'] !== '')
                ->values();

            $withoutOverlapping = $events
                ->filter(fn (array $event): bool => $event['without_overlapping'])
                ->count();

            return $this->check(
                'schedule.metadata',
                'Scheduler Metadata',
                $events->isNotEmpty() ? self::OK : self::WARN,
                $events->isNotEmpty() ? $events->count().' scheduled command(s) inspected.' : 'No scheduled commands were found.',
                [
                    'without_overlapping' => $withoutOverlapping,
                    'events' => $events->all(),
                ],
            );
        } catch (Throwable $exception) {
            return $this->check('schedule.metadata', 'Scheduler Metadata', self::FAIL, $exception->getMessage());
        }
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->check('queue.failed_jobs', 'Failed Jobs', self::WARN, 'failed_jobs table is missing.');
        }

        try {
            $count = DB::table('failed_jobs')->count();

            return $this->check(
                'queue.failed_jobs',
                'Failed Jobs',
                $count === 0 ? self::OK : self::WARN,
                $count === 0 ? 'No failed jobs found.' : "{$count} failed job(s) found.",
                ['count' => $count],
            );
        } catch (Throwable $exception) {
            return $this->check('queue.failed_jobs', 'Failed Jobs', self::FAIL, $exception->getMessage());
        }
    }

    private function diskSpace(): array
    {
        $free = @disk_free_space(base_path());

        if ($free === false) {
            return $this->check('disk.space', 'Disk Space', self::WARN, 'Unable to read disk space.');
        }

        $gigabytes = round($free / 1024 / 1024 / 1024, 2);

        return $this->check(
            'disk.space',
            'Disk Space',
            $free >= 1024 * 1024 * 1024 ? self::OK : self::WARN,
            "{$gigabytes} GB free.",
            ['free_bytes' => $free],
        );
    }

    private function timezone(): array
    {
        $timezone = (string) config('app.timezone');

        return $this->check(
            'app.timezone',
            'Timezone',
            $timezone === 'Asia/Baku' ? self::OK : self::WARN,
            $timezone,
        );
    }

    private function appEnvironment(): array
    {
        $environment = app()->environment();

        return $this->check(
            'app.environment',
            'APP_ENV',
            $environment === 'production' ? self::OK : self::WARN,
            $environment,
        );
    }

    private function appDebug(): array
    {
        $debug = (bool) config('app.debug');

        return $this->check(
            'app.debug',
            'APP_DEBUG',
            $debug && app()->environment('production') ? self::FAIL : ($debug ? self::WARN : self::OK),
            $debug ? 'Debug mode is enabled.' : 'Debug mode is disabled.',
        );
    }

    private function memoryLimit(): array
    {
        $limit = ini_get('memory_limit') ?: '';
        $bytes = $this->iniBytes($limit);

        return $this->check(
            'php.memory_limit',
            'Memory Limit',
            $bytes === -1 || $bytes >= 128 * 1024 * 1024 ? self::OK : self::WARN,
            $limit,
        );
    }

    private function executionTime(): array
    {
        $seconds = (int) ini_get('max_execution_time');

        return $this->check(
            'php.max_execution_time',
            'Execution Time',
            $seconds === 0 || $seconds >= 30 ? self::OK : self::WARN,
            $seconds === 0 ? 'Unlimited.' : "{$seconds} seconds.",
        );
    }

    private function migrationStatus(): array
    {
        try {
            $repository = app('migrator')->getRepository();

            return $this->check(
                'migrations.repository',
                'Migration Status',
                $repository->repositoryExists() ? self::OK : self::FAIL,
                $repository->repositoryExists() ? 'Migration repository exists.' : 'Migration repository is missing.',
            );
        } catch (Throwable $exception) {
            return $this->check('migrations.repository', 'Migration Status', self::FAIL, $exception->getMessage());
        }
    }

    private function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));

            return $this->check(
                'migrations.pending',
                'Pending Migrations',
                count($pending) === 0 ? self::OK : self::WARN,
                count($pending) === 0 ? 'No pending migrations.' : count($pending).' pending migration(s).',
                ['pending' => $pending],
            );
        } catch (Throwable $exception) {
            return $this->check('migrations.pending', 'Pending Migrations', self::FAIL, $exception->getMessage());
        }
    }

    private function expectedIndexes(): array
    {
        $expected = [
            'equipments' => [
                'equip_project_type_owner_active_idx' => ['project_id', 'equipment_type_id', 'ownership_type', 'active'],
                'equip_type_owner_idx' => ['equipment_type_id', 'ownership_type'],
            ],
            'equipment_daily_stats' => [
                'eds_date_project_owner_idx' => ['stat_date', 'project_id', 'ownership_type'],
                'eds_date_unit_project_idx' => ['stat_date', 'equipment_id', 'project_id'],
            ],
            'daily_unit_aggregates' => [
                'dua_project_type_owner_date_idx' => ['project_id', 'equipment_type_id', 'ownership_type', 'date'],
            ],
            'unit_foreign_geofence_intervals' => [
                'ufgi_status_foreign_project_idx' => ['status', 'foreign_project_id'],
                'ufgi_unit_status_idx' => ['unit_id', 'status'],
                'ufgi_status_last_position_idx' => ['status', 'last_position_at'],
            ],
        ];

        $missing = [];

        try {
            foreach ($expected as $table => $indexes) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table.': table missing';

                    continue;
                }

                $existing = Schema::getIndexes($table);

                foreach ($indexes as $name => $columns) {
                    if (! $this->hasIndex($existing, $name, $columns)) {
                        $missing[] = $table.'.'.$name;
                    }
                }
            }

            return $this->check(
                'database.expected_indexes',
                'Database Indexes',
                count($missing) === 0 ? self::OK : self::WARN,
                count($missing) === 0 ? 'Expected dashboard indexes are present.' : 'Missing expected index(es): '.implode(', ', $missing).'.',
                ['missing' => $missing],
            );
        } catch (Throwable $exception) {
            return $this->check('database.expected_indexes', 'Database Indexes', self::FAIL, $exception->getMessage());
        }
    }

    private function duplicateIndexes(): array
    {
        try {
            $duplicates = [];

            foreach (Schema::getTables() as $table) {
                $tableName = $this->schemaTableName($table);

                if (! $tableName) {
                    continue;
                }

                $seen = [];

                foreach (Schema::getIndexes($tableName) as $index) {
                    $columns = $index['columns'] ?? [];
                    $unique = (bool) ($index['unique'] ?? false);
                    $signature = ($unique ? 'unique:' : 'index:').implode(',', $columns);

                    if (isset($seen[$signature])) {
                        $duplicates[] = $tableName.'.'.($index['name'] ?? 'unnamed');
                    }

                    $seen[$signature] = true;
                }
            }

            return $this->check(
                'database.duplicate_indexes',
                'Duplicate Indexes',
                count($duplicates) === 0 ? self::OK : self::WARN,
                count($duplicates) === 0 ? 'No duplicate indexes detected.' : 'Duplicate index(es): '.implode(', ', $duplicates).'.',
                ['duplicates' => $duplicates],
            );
        } catch (Throwable $exception) {
            return $this->check('database.duplicate_indexes', 'Duplicate Indexes', self::FAIL, $exception->getMessage());
        }
    }

    private function tableSizes(): array
    {
        try {
            $sizes = [];

            foreach (Schema::getTables() as $table) {
                $tableName = $this->schemaTableName($table);

                if (! $tableName) {
                    continue;
                }

                $sizes[$tableName] = DB::table($tableName)->count();
            }

            arsort($sizes);

            return $this->check(
                'database.table_sizes',
                'Table Sizes',
                self::OK,
                count($sizes).' table(s) inspected.',
                ['largest' => array_slice($sizes, 0, 10, true)],
            );
        } catch (Throwable $exception) {
            return $this->check('database.table_sizes', 'Table Sizes', self::FAIL, $exception->getMessage());
        }
    }

    private function dashboardDataVersion(): array
    {
        try {
            $value = Cache::get(DashboardDataVersion::KEY);

            return $this->check(
                'dashboard.data_version',
                'DashboardDataVersion',
                is_numeric($value) ? self::OK : self::WARN,
                is_numeric($value) ? 'Version '.$value.'.' : 'Dashboard data version cache key is missing.',
            );
        } catch (Throwable $exception) {
            return $this->check('dashboard.data_version', 'DashboardDataVersion', self::FAIL, $exception->getMessage());
        }
    }

    private function dashboardSyncFlag(): array
    {
        return $this->check(
            'dashboard.sync.enabled',
            'Dashboard Sync',
            config('dashboard.sync.enabled') ? self::OK : self::WARN,
            config('dashboard.sync.enabled') ? 'Enabled.' : 'Disabled.',
        );
    }

    private function dashboardSyncStatus(): array
    {
        return $this->settingStatus('auto_sync_daily_last_status', 'Dashboard Sync Status');
    }

    private function geofenceMonitoringFlag(): array
    {
        return $this->check(
            'foreign_geofence.monitoring.enabled',
            'Monitoring',
            config('fleet.foreign_geofence.monitoring_enabled') ? self::OK : self::WARN,
            config('fleet.foreign_geofence.monitoring_enabled') ? 'Enabled.' : 'Disabled.',
        );
    }

    private function geofenceMonitoringSchedule(): array
    {
        $registered = collect($this->scheduleEvents())
            ->contains(fn (string $event): bool => str_contains($event, 'fleet:monitor-foreign-geofences'));

        return $this->check(
            'foreign_geofence.monitoring.schedule',
            'Monitoring Scheduler',
            $registered ? self::OK : (config('fleet.foreign_geofence.monitoring_enabled') ? self::FAIL : self::WARN),
            $registered ? 'Monitoring command is scheduled.' : 'Monitoring command is not currently scheduled.',
        );
    }

    private function dashboardCache(): array
    {
        try {
            $hasDataVersion = Cache::has(DashboardDataVersion::KEY);

            return $this->check(
                'dashboard.cache',
                'Dashboard Cache',
                $hasDataVersion ? self::OK : self::WARN,
                $hasDataVersion ? 'Dashboard cache version exists.' : 'Dashboard cache version is missing.',
            );
        } catch (Throwable $exception) {
            return $this->check('dashboard.cache', 'Dashboard Cache', self::FAIL, $exception->getMessage());
        }
    }

    private function lastSuccessfulSync(): array
    {
        return $this->settingStatus('auto_sync_units_last_status', 'Last Sync');
    }

    private function lastSuccessfulMonitoring(): array
    {
        try {
            if (! Schema::hasTable('unit_foreign_geofence_intervals')) {
                return $this->check('monitoring.last_success', 'Last Monitoring', self::WARN, 'Intervals table is missing.');
            }

            $last = UnitForeignGeofenceInterval::query()->max('calculated_at')
                ?? UnitForeignGeofenceInterval::query()->max('updated_at');

            return $this->check(
                'monitoring.last_success',
                'Last Monitoring',
                $last ? self::OK : self::WARN,
                $last ? 'Last interval update at '.$last.'.' : 'No monitoring interval updates found.',
            );
        } catch (Throwable $exception) {
            return $this->check('monitoring.last_success', 'Last Monitoring', self::FAIL, $exception->getMessage());
        }
    }

    private function environmentKeys(): array
    {
        $required = [
            'APP_ENV',
            'APP_DEBUG',
            'APP_TIMEZONE',
            'CACHE_STORE',
            'QUEUE_CONNECTION',
            'DB_CONNECTION',
            'WIALON_BASE_URL',
            'WIALON_TOKEN',
            'DASHBOARD_SYNC_ENABLED',
            'FOREIGN_GEOFENCE_MONITORING_ENABLED',
        ];

        $missing = collect($required)
            ->filter(fn (string $key): bool => env($key) === null)
            ->values()
            ->all();

        return $this->check(
            'environment.keys',
            'Environment',
            count($missing) === 0 ? self::OK : self::WARN,
            count($missing) === 0 ? 'Required environment keys are present.' : 'Missing keys: '.implode(', ', $missing).'.',
            ['missing' => $missing],
        );
    }

    private function tableCount(string $table, string $modelClass, int $minimumRows = 0): array
    {
        try {
            if (! Schema::hasTable($table)) {
                return $this->check('table.'.$table, $table, self::FAIL, 'Table is missing.');
            }

            $count = $modelClass::query()->count();

            return $this->check(
                'table.'.$table,
                $table,
                $count >= $minimumRows ? self::OK : self::WARN,
                "{$count} row(s).",
                ['count' => $count],
            );
        } catch (Throwable $exception) {
            return $this->check('table.'.$table, $table, self::FAIL, $exception->getMessage());
        }
    }

    private function ownershipData(): array
    {
        return $this->countCheck('dashboard.ownership', 'Ownership', Equipment::query()->select('ownership_type')->distinct()->count(), 1);
    }

    private function top20Data(): array
    {
        return $this->countCheck('dashboard.top20', 'Top20', DailyUnitAggregate::query()->count(), 1);
    }

    private function averageWidgetData(): array
    {
        return $this->countCheck('dashboard.average', 'Average Widgets', EquipmentDailyStat::query()->count(), 1);
    }

    private function geofenceWidgetData(): array
    {
        return $this->countCheck('dashboard.geofence', 'Geofence Widget', UnitForeignGeofenceInterval::query()->count(), 0);
    }

    private function layoutConfiguration(): array
    {
        $widgets = config('dashboard.widgets', []);

        return $this->check(
            'dashboard.layout',
            'Layout',
            count($widgets) > 0 ? self::OK : self::FAIL,
            count($widgets).' widget(s) configured.',
        );
    }

    private function routeExists(string $name, string $label): array
    {
        return $this->check(
            'route.'.$name,
            $label,
            Route::has($name) ? self::OK : self::FAIL,
            Route::has($name) ? 'Route exists.' : 'Route is missing.',
        );
    }

    private function commandExists(string $name, string $label): array
    {
        return $this->check(
            'command.'.$name,
            $label,
            array_key_exists($name, Artisan::all()) ? self::OK : self::FAIL,
            array_key_exists($name, Artisan::all()) ? 'Command exists.' : 'Command is missing.',
        );
    }

    private function fleetEquipment(): array
    {
        return $this->countCheck('fleet.equipment', 'Fleet Equipment', Equipment::query()->count(), 1);
    }

    private function lastUnitSync(): array
    {
        $last = Equipment::query()->max('last_synced_at');

        return $this->check(
            'fleet.last_sync',
            'Last Unit Sync',
            $last ? self::OK : self::WARN,
            $last ? 'Last unit sync at '.$last.'.' : 'No unit sync timestamp found.',
        );
    }

    private function storedPositions(): array
    {
        return $this->countCheck('fleet.positions', 'Stored Positions', Equipment::query()->whereNotNull('last_position_json')->count(), 1);
    }

    private function positionTimestamps(): array
    {
        $missing = $this->positionRows()
            ->filter(fn (Equipment $equipment): bool => blank($equipment->last_position_json['time'] ?? null)
                || blank($equipment->last_position_json['received_at'] ?? null))
            ->count();

        return $this->check(
            'fleet.position_timestamps',
            'Position Timestamps',
            $missing === 0 ? self::OK : self::WARN,
            $missing === 0 ? 'Stored positions include time and received_at.' : "{$missing} position(s) are missing time or received_at.",
            ['missing' => $missing],
        );
    }

    private function positionFreshness(): array
    {
        $latest = $this->positionRows()
            ->map(fn (Equipment $equipment): ?Carbon => $this->parseTime($equipment->last_position_json['time'] ?? null))
            ->filter()
            ->sort()
            ->last();

        if (! $latest) {
            return $this->check('fleet.position_freshness', 'Position Freshness', self::WARN, 'No valid position timestamps found.');
        }

        $minutes = $latest->diffInMinutes(now(config('app.timezone')));
        $maxAge = (int) config('fleet.foreign_geofence.position_max_age_minutes', config('fleet.foreign_geofence.stale_after_minutes', 30));

        return $this->check(
            'fleet.position_freshness',
            'Position Freshness',
            $minutes <= $maxAge ? self::OK : self::WARN,
            "Latest position is {$minutes} minute(s) old.",
            ['latest' => $latest->toDateTimeString(), 'max_age_minutes' => $maxAge],
        );
    }

    private function projectMapping(): array
    {
        return $this->countCheck('fleet.project_mapping', 'Project Mapping', Equipment::query()->whereNotNull('project_id')->count(), 1);
    }

    private function groupMapping(): array
    {
        return $this->countCheck(
            'fleet.group_mapping',
            'Group Mapping',
            Equipment::query()
                ->where(fn ($query) => $query->whereNotNull('project_wialon_group_id')->orWhereNotNull('matched_wialon_group_id'))
                ->count(),
            1
        );
    }

    private function aggregateBackfillStatus(): array
    {
        return $this->countCheck('fleet.aggregates', 'Backfill', DailyUnitAggregate::query()->count(), 1);
    }

    private function currentIntervals(): array
    {
        return $this->countCheck('geofence.current_intervals', 'Current Intervals', UnitForeignGeofenceInterval::query()->count(), 0);
    }

    private function openIntervals(): array
    {
        return $this->countCheck(
            'geofence.open_intervals',
            'Open Intervals',
            UnitForeignGeofenceInterval::query()->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)->count(),
            0
        );
    }

    private function duplicateOpenIntervals(): array
    {
        $duplicates = UnitForeignGeofenceInterval::query()
            ->select('unit_id')
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->whereNotNull('unit_id')
            ->groupBy('unit_id')
            ->havingRaw('count(*) > 1')
            ->count();

        return $this->check(
            'geofence.duplicate_open_intervals',
            'Duplicate Intervals',
            $duplicates === 0 ? self::OK : self::FAIL,
            $duplicates === 0 ? 'No duplicate open intervals.' : "{$duplicates} unit(s) have duplicate open intervals.",
        );
    }

    private function invalidIntervals(): array
    {
        $invalid = UnitForeignGeofenceInterval::query()
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->where(fn ($query) => $query
                ->whereNull('unit_id')
                ->orWhereNull('foreign_project_id')
                ->orWhereNull('foreign_geofence_id'))
            ->count();

        return $this->check(
            'geofence.invalid_intervals',
            'Invalid Intervals',
            $invalid === 0 ? self::OK : self::WARN,
            $invalid === 0 ? 'No invalid open intervals.' : "{$invalid} invalid open interval(s).",
        );
    }

    private function staleIntervals(): array
    {
        $cutoff = now(config('app.timezone'))->subMinutes((int) config('fleet.foreign_geofence.stale_after_minutes', 30));
        $stale = UnitForeignGeofenceInterval::query()
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->whereNotNull('last_position_at')
            ->where('last_position_at', '<', $cutoff)
            ->count();

        return $this->check(
            'geofence.stale_intervals',
            'Stale Intervals',
            $stale === 0 ? self::OK : self::WARN,
            $stale === 0 ? 'No stale open intervals.' : "{$stale} stale open interval(s).",
        );
    }

    private function missingIntervalTimestamps(): array
    {
        $missing = UnitForeignGeofenceInterval::query()
            ->where('status', UnitForeignGeofenceInterval::STATUS_OPEN)
            ->where(fn ($query) => $query->whereNull('entered_at')->orWhereNull('last_position_at'))
            ->count();

        return $this->check(
            'geofence.missing_timestamps',
            'Missing Timestamps',
            $missing === 0 ? self::OK : self::WARN,
            $missing === 0 ? 'No missing timestamps.' : "{$missing} open interval(s) have missing timestamps.",
        );
    }

    private function settingStatus(string $key, string $label): array
    {
        if (! Schema::hasTable('settings')) {
            return $this->check('setting.'.$key, $label, self::WARN, 'settings table is missing.');
        }

        $value = Setting::query()->where('key', $key)->value('value');

        return $this->check(
            'setting.'.$key,
            $label,
            $value === 'success' ? self::OK : self::WARN,
            $value ? "Status: {$value}." : 'Status is missing.',
        );
    }

    private function countCheck(string $key, string $label, int $count, int $minimum): array
    {
        return $this->check(
            $key,
            $label,
            $count >= $minimum ? self::OK : self::WARN,
            "{$count} row(s).",
            ['count' => $count],
        );
    }

    /**
     * @return Collection<int, Equipment>
     */
    private function positionRows(): Collection
    {
        return Equipment::query()
            ->whereNotNull('last_position_json')
            ->get(['id', 'last_position_json']);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  array<int, string>  $columns
     */
    private function hasIndex(array $indexes, string $name, array $columns): bool
    {
        foreach ($indexes as $index) {
            $indexName = (string) ($index['name'] ?? '');
            $indexColumns = $index['columns'] ?? [];

            if ($indexName === $name || $indexColumns === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function schemaTableName(array $table): ?string
    {
        $name = $table['name'] ?? $table['table'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return array<int, string>
     */
    private function scheduleEvents(): array
    {
        try {
            return collect(app(Schedule::class)->events())
                ->map(fn ($event): string => trim((string) $event->command))
                ->filter()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
