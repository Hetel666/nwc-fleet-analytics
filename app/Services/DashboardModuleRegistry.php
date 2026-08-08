<?php

namespace App\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class DashboardModuleRegistry
{
    private const REQUIRED_FIELDS = [
        'title',
        'tab',
        'source_report',
        'collector_command',
        'manual_command',
        'auto_schedule',
        'result_tables',
        'shared_result_tables',
        'read_service',
        'api_endpoints',
        'frontend_widgets',
        'safe_resync_scope',
        'dry_run_tables',
        'writes_shared_tables',
        'failure_isolation',
    ];

    /** @return Collection<string, array<string, mixed>> */
    public function all(): Collection
    {
        return collect(config('dashboard_modules.modules', []))
            ->map(fn (array $module, string $code): array => $this->normalize($code, $module));
    }

    /** @return array<string, mixed> */
    public function get(string $code): array
    {
        $module = $this->all()->get($code);

        if ($module === null) {
            throw new InvalidArgumentException("Unknown dashboard module: {$code}");
        }

        return $module;
    }

    /** @return array<int, string> */
    public function codes(): array
    {
        return $this->all()->keys()->values()->all();
    }

    /** @return Collection<string, array<string, mixed>> */
    public function forHistoricalSection(?string $section): Collection
    {
        return $this->all()
            ->filter(fn (array $module): bool => ($module['dashboard_section'] ?? null) === $section);
    }

    /** @return array<string, array<int, string>> */
    public function validationErrors(): array
    {
        $errors = [];

        foreach (config('dashboard_modules.modules', []) as $code => $module) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $module)) {
                    $errors[$code][] = "Missing {$field}";
                }
            }

            foreach (['result_tables', 'shared_result_tables', 'api_endpoints', 'frontend_widgets', 'dry_run_tables'] as $listField) {
                if (array_key_exists($listField, $module) && ! is_array($module[$listField])) {
                    $errors[$code][] = "{$listField} must be an array";
                }
            }

            if (array_key_exists('safe_resync_scope', $module) && ! is_array($module['safe_resync_scope'])) {
                $errors[$code][] = 'safe_resync_scope must be an array';
            }
        }

        return $errors;
    }

    /** @param  array<string, mixed>  $module */
    private function normalize(string $code, array $module): array
    {
        return [
            'code' => $code,
            'title' => (string) ($module['title'] ?? $code),
            'tab' => (string) ($module['tab'] ?? ''),
            'dashboard_section' => $module['dashboard_section'] ?? null,
            'source_report' => (string) ($module['source_report'] ?? ''),
            'collector_command' => (string) ($module['collector_command'] ?? ''),
            'manual_command' => (string) ($module['manual_command'] ?? ''),
            'auto_schedule' => (string) ($module['auto_schedule'] ?? ''),
            'result_tables' => array_values($module['result_tables'] ?? []),
            'shared_result_tables' => array_values($module['shared_result_tables'] ?? []),
            'read_service' => (string) ($module['read_service'] ?? ''),
            'collector_service' => (string) ($module['collector_service'] ?? ''),
            'controller' => (string) ($module['controller'] ?? ''),
            'api_endpoints' => array_values($module['api_endpoints'] ?? []),
            'frontend_widgets' => array_values($module['frontend_widgets'] ?? []),
            'safe_resync_scope' => $module['safe_resync_scope'] ?? [],
            'dry_run_tables' => array_values($module['dry_run_tables'] ?? []),
            'writes_shared_tables' => (bool) ($module['writes_shared_tables'] ?? false),
            'failure_isolation' => (string) ($module['failure_isolation'] ?? ''),
        ];
    }
}
