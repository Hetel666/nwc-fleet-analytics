<?php

namespace App\Services;

use App\Models\HistoricalRecalculation;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardResyncDryRunPlanner
{
    public function __construct(private DashboardModuleRegistry $modules) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $taskPreview
     * @return array<string, mixed>
     */
    public function plan(array $payload, array $taskPreview = []): array
    {
        $module = $this->moduleForPayload($payload);
        $dateFrom = Carbon::parse($payload['date_from'])->toDateString();
        $dateTo = Carbon::parse($payload['date_to'])->toDateString();
        $projectIds = $this->projectIds($payload);
        $force = (bool) ($payload['force'] ?? false);
        $tables = $this->tablePlans($module, $dateFrom, $dateTo, $projectIds);
        $existingRows = array_sum(array_map(
            fn (array $table): int => (int) ($table['existing_rows'] ?? 0),
            $tables
        ));

        return [
            'dashboard_code' => $module['code'],
            'title' => $module['title'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'scope' => (string) ($payload['scope'] ?? HistoricalRecalculation::SCOPE_ALL_PROJECTS),
            'project_ids' => $projectIds,
            'force' => $force,
            'isolation' => $module['safe_resync_scope']['status'] ?? 'unknown',
            'writes_shared_tables' => (bool) ($module['writes_shared_tables'] ?? false),
            'safe_resync_keys' => $module['safe_resync_scope']['keys'] ?? [],
            'risk' => $module['safe_resync_scope']['risk'] ?? '',
            'source_report' => $module['source_report'],
            'collector_command' => $module['collector_command'],
            'manual_command' => $module['manual_command'],
            'task_preview' => [
                'days' => (int) ($taskPreview['days'] ?? 0),
                'project_groups' => (int) ($taskPreview['project_groups'] ?? 0),
                'fetch_tasks' => (int) ($taskPreview['fetch_tasks'] ?? 0),
                'aggregate_tasks' => (int) ($taskPreview['aggregate_tasks'] ?? 0),
                'total_tasks' => (int) ($taskPreview['total_tasks'] ?? 0),
            ],
            'tables' => $tables,
            'existing_rows_in_scope' => $existingRows,
            'warnings' => $this->warnings($module, $tables, $force, $existingRows),
            'read_only' => true,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function moduleForPayload(array $payload): array
    {
        $dashboardCode = trim((string) ($payload['dashboard_code'] ?? ''));

        if ($dashboardCode !== '') {
            return $this->modules->get($dashboardCode);
        }

        $section = $payload['dashboard_section'] ?? HistoricalRecalculation::SECTION_DAILY_AVERAGES;
        $module = $this->modules->forHistoricalSection($section)->first();

        if ($module !== null) {
            return $module;
        }

        return $this->modules->get((string) $section);
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<int, int>  $projectIds
     * @return array<int, array<string, mixed>>
     */
    private function tablePlans(array $module, string $dateFrom, string $dateTo, array $projectIds): array
    {
        return collect($module['dry_run_tables'] ?? [])
            ->map(function (array $rule) use ($dateFrom, $dateTo, $projectIds, $module): array {
                $table = (string) ($rule['table'] ?? '');
                $shared = in_array($table, $module['shared_result_tables'] ?? [], true);

                if ($table === '' || ! Schema::hasTable($table)) {
                    return [
                        'table' => $table,
                        'shared' => $shared,
                        'filterable' => false,
                        'existing_rows' => null,
                        'note' => 'Table is missing in this environment.',
                    ];
                }

                $query = DB::table($table);
                $filters = $this->applyDateFilter($query, $table, $rule, $dateFrom, $dateTo);
                $filters = array_merge($filters, $this->applyProjectFilter($query, $table, $rule, $projectIds));

                return [
                    'table' => $table,
                    'shared' => $shared,
                    'filterable' => true,
                    'existing_rows' => (int) $query->count(),
                    'filters' => $filters,
                    'note' => (string) ($rule['note'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<int, string>
     */
    private function applyDateFilter(Builder $query, string $table, array $rule, string $dateFrom, string $dateTo): array
    {
        $dateColumn = (string) ($rule['date_column'] ?? '');
        $dateFromColumn = (string) ($rule['date_from_column'] ?? '');
        $dateToColumn = (string) ($rule['date_to_column'] ?? '');
        $filters = [];

        if ($dateColumn !== '' && Schema::hasColumn($table, $dateColumn)) {
            $query->whereBetween($dateColumn, [$dateFrom, $dateTo]);
            $filters[] = "{$dateColumn} between {$dateFrom} and {$dateTo}";

            return $filters;
        }

        if ($dateFromColumn !== '' && $dateToColumn !== ''
            && Schema::hasColumn($table, $dateFromColumn)
            && Schema::hasColumn($table, $dateToColumn)) {
            $query->whereDate($dateFromColumn, '<=', $dateTo)
                ->whereDate($dateToColumn, '>=', $dateFrom);
            $filters[] = "{$dateFromColumn}/{$dateToColumn} overlaps {$dateFrom}..{$dateTo}";
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<int, int>  $projectIds
     * @return array<int, string>
     */
    private function applyProjectFilter(Builder $query, string $table, array $rule, array $projectIds): array
    {
        $projectColumn = (string) ($rule['project_column'] ?? 'project_id');

        if ($projectIds === [] || $projectColumn === '' || ! Schema::hasColumn($table, $projectColumn)) {
            return [];
        }

        $query->whereIn($projectColumn, $projectIds);

        return [$projectColumn.' in ['.implode(', ', $projectIds).']'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, int>
     */
    private function projectIds(array $payload): array
    {
        if (($payload['scope'] ?? null) !== HistoricalRecalculation::SCOPE_SELECTED_PROJECTS) {
            return [];
        }

        return collect($payload['project_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<int, array<string, mixed>>  $tables
     * @return array<int, string>
     */
    private function warnings(array $module, array $tables, bool $force, int $existingRows): array
    {
        $warnings = [];

        if ((bool) ($module['writes_shared_tables'] ?? false)) {
            $warnings[] = 'This module writes shared dashboard tables; verify dependent widgets before force resync.';
        }

        if (($module['safe_resync_scope']['status'] ?? '') !== 'isolated') {
            $warnings[] = 'Resync scope is not fully isolated: '.($module['safe_resync_scope']['risk'] ?? 'review required.');
        }

        if ($force && $existingRows > 0) {
            $warnings[] = "Force mode may replace existing rows in the selected scope ({$existingRows} rows currently match).";
        }

        foreach ($tables as $table) {
            if (($table['filterable'] ?? false) === false) {
                $warnings[] = 'Could not count table '.$table['table'].': '.$table['note'];
            }
        }

        return array_values(array_unique($warnings));
    }
}
