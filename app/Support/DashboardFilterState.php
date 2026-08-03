<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

class DashboardFilterState
{
    public const COOKIE_NAME = 'fleet_dashboard_filters';

    public const FILTER_KEYS = [
        'period',
        'quick_range',
        'date_from',
        'date_to',
        'from',
        'to',
        'project_id',
        'equipment_type_id',
        'ownership_type',
    ];

    private const PERIODS = ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'custom'];

    public function filtersForRequest(Request $request, array $overrides = []): array
    {
        $query = $this->presentQueryFilters($request);
        $remembered = $this->rememberedFilters($request);

        if ($remembered !== [] && ! $this->hasValidDateRange($query)) {
            unset($query['period'], $query['quick_range'], $query['date_from'], $query['date_to'], $query['from'], $query['to']);

            $query = array_replace($remembered, $query);
        }

        return array_replace($query, $overrides);
    }

    public function selectedPeriod(Request $request, array $filters): string
    {
        $period = $request->query('period')
            ?? $request->query('quick_range')
            ?? $filters['period']
            ?? $filters['quick_range']
            ?? 'custom';

        return in_array($period, self::PERIODS, true) ? $period : 'custom';
    }

    private function presentQueryFilters(Request $request): array
    {
        $filters = [];

        foreach (self::FILTER_KEYS as $key) {
            if ($request->query->has($key)) {
                $value = $request->query($key);

                if (is_scalar($value) || $value === null) {
                    $filters[$key] = $value;
                }
            }
        }

        return $filters;
    }

    private function rememberedFilters(Request $request): array
    {
        $userId = $request->user()?->id;

        if (! $userId) {
            return [];
        }

        $cookie = $request->cookies->get(self::COOKIE_NAME);

        if (! is_string($cookie) || trim($cookie) === '') {
            return [];
        }

        try {
            $state = json_decode(rawurldecode($cookie), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($state)) {
            return [];
        }

        $filters = $state[(string) $userId] ?? null;

        return is_array($filters) ? $this->normalizeStoredFilters($filters) : [];
    }

    private function normalizeStoredFilters(array $filters): array
    {
        $from = $this->validDate($filters['date_from'] ?? null);
        $to = $this->validDate($filters['date_to'] ?? null);

        if (! $from || ! $to || $from > $to) {
            return [];
        }

        $period = $filters['period'] ?? $filters['quick_range'] ?? 'custom';

        if (! in_array($period, self::PERIODS, true)) {
            $period = 'custom';
        }

        $normalized = [
            'period' => $period,
            'quick_range' => $period,
            'date_from' => $from,
            'date_to' => $to,
        ];

        foreach (['project_id', 'equipment_type_id', 'ownership_type'] as $key) {
            $value = $filters[$key] ?? null;

            if (is_scalar($value)) {
                $value = trim((string) $value);

                if ($value !== '' && $value !== 'all') {
                    $normalized[$key] = $value;
                }
            }
        }

        return $normalized;
    }

    private function hasValidDateRange(array $filters): bool
    {
        $from = $this->validDate($filters['date_from'] ?? $filters['from'] ?? null);
        $to = $this->validDate($filters['date_to'] ?? $filters['to'] ?? null);

        return $from !== null && $to !== null && $from <= $to;
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone', 'Asia/Baku'));
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
