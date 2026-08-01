<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\WialonReportSyncItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutoSyncFleetData extends Command
{
    protected $signature = 'fleet:auto-sync {--force : Run enabled tasks even when they are not due yet}';

    protected $description = 'Run enabled fleet synchronization tasks according to dashboard settings.';

    public function handle(): int
    {
        if (! $this->settingBool('auto_sync_enabled', true)) {
            $this->line('Automatic synchronization is disabled.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $ran = false;
        $failed = false;

        foreach ($this->intervalTasks() as $task) {
            if (! $this->settingBool($task['enabled_key'], true)) {
                continue;
            }

            if (! $force && ! $this->intervalTaskDue($task)) {
                continue;
            }

            $ran = true;
            $failed = ! $this->runTask($task['name'], $task['command']) || $failed;
        }

        if ($this->settingBool('auto_sync_daily_enabled', true) && ($force || $this->dailyTaskDue())) {
            $ran = true;
            $failed = ! $this->runDailyStats() || $failed;
        }

        if (! $ran) {
            $this->line('No synchronization task is due.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function intervalTasks(): array
    {
        return [
            [
                'name' => 'units',
                'command' => 'fleet:sync-units',
                'enabled_key' => 'auto_sync_units_enabled',
                'interval_key' => 'auto_sync_units_interval_minutes',
                'default_interval' => 60,
            ],
            [
                'name' => 'geofences',
                'command' => 'fleet:sync-geofences',
                'enabled_key' => 'auto_sync_geofences_enabled',
                'interval_key' => 'auto_sync_geofences_interval_minutes',
                'default_interval' => 1440,
            ],
        ];
    }

    private function intervalTaskDue(array $task): bool
    {
        $interval = max(5, (int) $this->setting($task['interval_key'], $task['default_interval']));
        $lastRunAt = $this->setting("auto_sync_{$task['name']}_last_run_at");

        if (! $lastRunAt) {
            return true;
        }

        return Carbon::parse($lastRunAt)->addMinutes($interval)->isPast();
    }

    private function dailyTaskDue(): bool
    {
        $interval = max(60, (int) $this->setting('auto_sync_daily_interval_minutes', 180));
        $lastRunAt = $this->setting('auto_sync_daily_last_run_at');

        if (! $lastRunAt) {
            return true;
        }

        return Carbon::parse($lastRunAt)->addMinutes($interval)->isPast();
    }

    private function runDailyStats(): bool
    {
        $days = max(1, min(7, (int) $this->setting('auto_sync_daily_recent_days', 3)));
        $dailySuccess = true;
        $top20Success = true;
        $efficiencySuccess = true;
        $geozonSuccess = true;
        $geofenceViolationsSuccess = true;
        $dailyMessages = [];
        $top20Messages = [];
        $efficiencyMessages = [];
        $geozonMessages = [];
        $geofenceViolationsMessages = [];
        $top20Limit = max(1, min(50, (int) $this->setting('auto_sync_top20_batch_limit', 10)));
        $rangeFrom = now(config('app.timezone'))->subDays($days)->startOfDay();
        $rangeTo = now(config('app.timezone'))->subDay()->endOfDay();

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now(config('app.timezone'))->subDays($offset + 1)->toDateString();
            $reportOk = $this->runArtisanCommand('fleet:sync-report-stats', ['--date' => $date, '--force' => true]);
            $aggregateOk = $this->runArtisanCommand('fleet:aggregate-daily', ['--date' => $date]);
            $top20Ok = $this->runBatchedReportCommand(
                'fleet:sync-engine-hours-report',
                ['--date' => $date, '--limit' => $top20Limit],
                WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
                $date
            );
            $geozonOk = $this->runArtisanCommand('fleet:sync-geozon-api', [
                '--from' => $date.' 00:00:00',
                '--to' => $date.' 23:59:59',
                '--force' => true,
            ]);
            $efficiencyOk = $this->runArtisanCommand('fleet:queue-efficiency-sync', [
                '--from' => $date,
                '--to' => $date,
                '--force' => true,
            ]);
            $dailySuccess = $dailySuccess && $aggregateOk['ok'] && $reportOk['ok'];
            $top20Success = $top20Success && $top20Ok['ok'];
            $geozonSuccess = $geozonSuccess && $geozonOk['ok'];
            $efficiencySuccess = $efficiencySuccess && $efficiencyOk['ok'];

            $dailyMessages[] = $date.': '.trim($reportOk['output'].' '.$aggregateOk['output']);
            $top20Messages[] = $date.': '.trim($top20Ok['output']);
            $geozonMessages[] = $date.': '.trim($geozonOk['output']);
            $efficiencyMessages[] = $date.': '.trim($efficiencyOk['output']);
        }

        $geofenceViolationsOk = $this->runArtisanCommand('fleet:sync-geofence-violations-report', [
            '--from' => $rangeFrom->toDateTimeString(),
            '--to' => $rangeTo->toDateTimeString(),
            '--force' => true,
        ]);
        $geofenceViolationsSuccess = $geofenceViolationsOk['ok'];
        $geofenceViolationsMessages[] = $rangeFrom->toDateString().' - '.$rangeTo->toDateString()
            .': '.trim($geofenceViolationsOk['output']);

        $this->storeTaskResult('daily', $dailySuccess, implode(' | ', array_filter($dailyMessages)));
        $this->storeTaskResult('top20', $top20Success, implode(' | ', array_filter($top20Messages)));
        $this->storeTaskResult('geozon', $geozonSuccess, implode(' | ', array_filter($geozonMessages)));
        $this->storeTaskResult(
            'geofence_violations',
            $geofenceViolationsSuccess,
            implode(' | ', array_filter($geofenceViolationsMessages))
        );
        $this->storeTaskResult('efficiency', $efficiencySuccess, implode(' | ', array_filter($efficiencyMessages)));

        Setting::updateOrCreate(
            ['key' => 'auto_sync_daily_last_run_date'],
            ['value' => now(config('app.timezone'))->toDateString(), 'is_secret' => false]
        );

        return $dailySuccess
            && $top20Success
            && $geozonSuccess
            && $geofenceViolationsSuccess
            && $efficiencySuccess;
    }

    /**
     * Run all ready batches and only report success when no incomplete items remain.
     *
     * @return array{ok: bool, output: string}
     */
    private function runBatchedReportCommand(string $command, array $parameters, string $syncType, string $date): array
    {
        $outputs = [];
        $maxBatches = max(1, (int) config('fleet.wialon.auto_sync_max_batches', 100));

        for ($batch = 1; $batch <= $maxBatches; $batch++) {
            $result = $this->runArtisanCommand($command, $parameters);
            $outputs[] = $result['output'];

            if (! $result['ok']) {
                return ['ok' => false, 'output' => implode(' ', array_filter($outputs))];
            }

            if (! $this->hasReadyReportItems($syncType, $date)) {
                break;
            }
        }

        $incomplete = WialonReportSyncItem::query()
            ->where('sync_type', $syncType)
            ->where('report_date', $date)
            ->whereIn('status', [
                WialonReportSyncItem::STATUS_PENDING,
                WialonReportSyncItem::STATUS_RUNNING,
                WialonReportSyncItem::STATUS_RETRY,
                WialonReportSyncItem::STATUS_FAILED,
            ])
            ->count();

        if ($incomplete > 0) {
            $outputs[] = "Incomplete checkpoint items: {$incomplete}.";
        }

        return [
            'ok' => $incomplete === 0,
            'output' => implode(' ', array_filter($outputs)),
        ];
    }

    private function hasReadyReportItems(string $syncType, string $date): bool
    {
        return WialonReportSyncItem::query()
            ->where('sync_type', $syncType)
            ->where('report_date', $date)
            ->whereIn('status', [
                WialonReportSyncItem::STATUS_PENDING,
                WialonReportSyncItem::STATUS_RETRY,
            ])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now(config('app.timezone')));
            })
            ->exists();
    }

    private function runTask(string $name, string $command): bool
    {
        $result = $this->runArtisanCommand($command);
        $this->storeTaskResult($name, $result['ok'], $result['output']);

        return $result['ok'];
    }

    private function runArtisanCommand(string $command, array $parameters = []): array
    {
        try {
            $code = Artisan::call($command, $parameters);
            $output = trim(Artisan::output());

            if ($output !== '') {
                $this->line($output);
            }

            return [
                'ok' => $code === self::SUCCESS,
                'output' => $output,
            ];
        } catch (Throwable $exception) {
            Log::warning('Automatic synchronization task failed', [
                'command' => $command,
                'parameters' => $parameters,
                'message' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());

            return [
                'ok' => false,
                'output' => $exception->getMessage(),
            ];
        }
    }

    private function storeTaskResult(string $name, bool $success, string $message): void
    {
        $values = [
            "auto_sync_{$name}_last_run_at" => now(config('app.timezone'))->toDateTimeString(),
            "auto_sync_{$name}_last_status" => $success ? 'success' : 'failed',
            "auto_sync_{$name}_last_message" => mb_substr($message, 0, 1000),
        ];

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'is_secret' => false]);
        }
    }

    private function settingBool(string $key, bool $default = false): bool
    {
        $value = $this->setting($key, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return Setting::query()->where('key', $key)->value('value') ?? $default;
    }
}
