<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DashboardPerformanceProfiler
{
    private bool $enabled;

    private bool $listenerRegistered = false;

    private bool $active = false;

    private ?int $startedAt = null;

    private int $queryCount = 0;

    private float $queryTimeMs = 0.0;

    private int $startMemory = 0;

    private string $requestId = '';

    /** @var array<int, array<string, mixed>> */
    private array $segments = [];

    /** @var array<string, mixed> */
    private array $lastProfile = [];

    public function __construct()
    {
        $this->enabled = (bool) config('fleet.dashboard.performance_logging_enabled', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function begin(string $operation, array $context = [], bool $force = false): void
    {
        $this->active = $this->enabled || $force;

        if (! $this->active) {
            return;
        }

        $this->registerQueryListener();
        $this->startedAt = hrtime(true);
        $this->queryCount = 0;
        $this->queryTimeMs = 0.0;
        $this->startMemory = memory_get_usage(true);
        $this->segments = [];
        $this->requestId = (string) ($context['request_id'] ?? Str::uuid());
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function measure(string $name, callable $callback): mixed
    {
        if (! $this->active) {
            return $callback();
        }

        $queryCountBefore = $this->queryCount;
        $queryTimeBefore = $this->queryTimeMs;
        $memoryBefore = memory_get_usage(true);
        $startedAt = hrtime(true);
        $status = 'ok';

        try {
            return $callback();
        } catch (Throwable $exception) {
            $status = 'error: '.$exception::class;

            throw $exception;
        } finally {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $memoryAfter = memory_get_usage(true);

            $this->segments[] = [
                'name' => $name,
                'duration_ms' => round($durationMs, 2),
                'query_count' => $this->queryCount - $queryCountBefore,
                'db_time_ms' => round($this->queryTimeMs - $queryTimeBefore, 2),
                'memory_delta_mb' => $this->bytesToMb($memoryAfter - $memoryBefore),
                'peak_memory_mb' => $this->bytesToMb(memory_get_peak_usage(true)),
                'status' => $status,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function finish(string $operation, array $context = [], mixed $result = null, bool $forceLog = false): array
    {
        if (! $this->active || $this->startedAt === null) {
            return [];
        }

        $durationMs = (hrtime(true) - $this->startedAt) / 1_000_000;
        $profile = [
            'request_id' => $this->requestId,
            'operation' => $operation,
            'duration_ms' => round($durationMs, 2),
            'query_count' => $this->queryCount,
            'db_time_ms' => round($this->queryTimeMs, 2),
            'memory_start_mb' => $this->bytesToMb($this->startMemory),
            'memory_current_mb' => $this->bytesToMb(memory_get_usage(true)),
            'peak_memory_mb' => $this->bytesToMb(memory_get_peak_usage(true)),
            'result_size_kb' => $this->resultSizeKb($result),
            'context' => $this->sanitizeContext($context),
            'segments' => $this->segments,
        ];

        $this->lastProfile = $profile;

        $slowRequestMs = max(0, (int) config('fleet.dashboard.slow_request_ms', 3000));

        if ($forceLog || ($this->enabled && $durationMs >= $slowRequestMs)) {
            Log::info('Dashboard performance profile', $profile);
        }

        $this->active = false;

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastProfile(): array
    {
        return $this->lastProfile;
    }

    private function registerQueryListener(): void
    {
        if ($this->listenerRegistered) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            if (! $this->active) {
                return;
            }

            $this->queryCount++;
            $this->queryTimeMs += (float) $query->time;
        });

        $this->listenerRegistered = true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sensitive = ['password', 'token', 'cookie', 'secret', 'authorization', 'access_token'];

        return collect($context)
            ->reject(fn (mixed $value, string $key): bool => in_array(strtolower($key), $sensitive, true))
            ->map(function (mixed $value): mixed {
                if (is_array($value)) {
                    return $this->sanitizeContext($value);
                }

                if (is_string($value) && strlen($value) > 120) {
                    return substr($value, 0, 120).'...';
                }

                return $value;
            })
            ->all();
    }

    private function resultSizeKb(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? 0.0 : round(strlen($encoded) / 1024, 2);
    }

    private function bytesToMb(int|float $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }
}
