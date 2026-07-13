<?php

namespace App\Console\Commands;

use App\Models\Setting;
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
        $time = $this->validTime((string) $this->setting('auto_sync_daily_time', '02:10'));
        $now = now(config('app.timezone'));

        if ($now->format('H:i') < $time) {
            return false;
        }

        return $this->setting('auto_sync_daily_last_run_date') !== $now->toDateString();
    }

    private function runDailyStats(): bool
    {
        $days = max(1, min(7, (int) $this->setting('auto_sync_daily_recent_days', 1)));
        $success = true;
        $messages = [];

        for ($offset = $days; $offset >= 1; $offset--) {
            $date = now(config('app.timezone'))->subDays($offset)->toDateString();
            $dailyOk = $this->runArtisanCommand('fleet:sync-daily', ['--date' => $date]);
            $aggregateOk = $this->runArtisanCommand('fleet:aggregate-daily', ['--date' => $date]);
            $reportOk = $this->runArtisanCommand('fleet:sync-report-stats', ['--date' => $date]);
            $success = $success && $dailyOk['ok'] && $aggregateOk['ok'] && $reportOk['ok'];
            $messages[] = $date.': '.trim($dailyOk['output'].' '.$aggregateOk['output'].' '.$reportOk['output']);
        }

        $this->storeTaskResult('daily', $success, implode(' | ', array_filter($messages)));
        Setting::updateOrCreate(
            ['key' => 'auto_sync_daily_last_run_date'],
            ['value' => now(config('app.timezone'))->toDateString(), 'is_secret' => false]
        );

        return $success;
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

    private function validTime(string $time): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '02:10';
    }
}
