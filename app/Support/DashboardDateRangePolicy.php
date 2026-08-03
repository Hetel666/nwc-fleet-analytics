<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class DashboardDateRangePolicy
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{from: string, to: string, days: int}
     */
    public function normalize(array $input, string $context = 'dashboard'): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Baku');
        $defaultFrom = $input['_default_from'] ?? now($timezone)->startOfMonth();
        $defaultTo = $input['_default_to'] ?? now($timezone);
        $from = $this->safeDate($input['date_from'] ?? $input['from'] ?? null, $defaultFrom, $timezone);
        $to = $this->safeDate($input['date_to'] ?? $input['to'] ?? null, $defaultTo, $timezone);

        if ($from > $to) {
            if ($this->reversedRangeMode() === 'reject') {
                throw new InvalidArgumentException('Dashboard date range start date must not be after end date.');
            }

            [$from, $to] = [$to, $from];
        }

        $days = (int) CarbonImmutable::parse($from, $timezone)->diffInDays(CarbonImmutable::parse($to, $timezone)) + 1;
        $maxDays = $this->maxDays($context);

        if ($maxDays > 0 && $days > $maxDays) {
            throw new InvalidArgumentException("Dashboard {$context} date range exceeds {$maxDays} days.");
        }

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
        ];
    }

    private function maxDays(string $context): int
    {
        $key = match ($context) {
            'modal', 'drilldown' => 'modal_max_period_days',
            'export', 'excel' => 'export_max_period_days',
            default => 'max_period_days',
        };

        return max(0, (int) config('fleet.dashboard.'.$key, 0));
    }

    private function reversedRangeMode(): string
    {
        return config('fleet.dashboard.reversed_date_range_mode', 'swap') === 'reject'
            ? 'reject'
            : 'swap';
    }

    private function safeDate(mixed $value, mixed $fallback, string $timezone): string
    {
        try {
            return CarbonImmutable::parse($value ?? $fallback, $timezone)->toDateString();
        } catch (Throwable) {
            return CarbonImmutable::parse($fallback, $timezone)->toDateString();
        }
    }
}
