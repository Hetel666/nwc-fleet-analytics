<?php

namespace App\Console\Commands;

use App\Models\GeofenceViolationReportRow;
use App\Models\GeofenceViolationSyncItem;
use App\Models\ProjectWialonGroup;
use App\Models\UnitForeignGeofenceInterval;
use App\Support\GeofenceExcludedGroups;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PurgeExcludedGeofenceGroups extends Command
{
    protected $signature = 'geofence:purge-excluded-groups
        {--dry-run : Show matching rows without deleting anything}
        {--execute : Delete matching rows}
        {--backup-confirmed : Confirm that a verified production backup exists before deletion}';

    protected $description = 'Audit or purge geofence-only data that belongs to excluded Wialon object groups.';

    public function handle(GeofenceExcludedGroups $excludedGroups): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run') || ! $execute;

        if ($execute && $dryRun && (bool) $this->option('dry-run')) {
            $this->error('Use either --dry-run or --execute, not both.');

            return self::INVALID;
        }

        if ($execute && ! (bool) $this->option('backup-confirmed')) {
            $this->error('Backup confirmation is required before deletion. Re-run with --execute --backup-confirmed after verifying a production backup.');

            return self::INVALID;
        }

        $summary = $this->summary($excludedGroups);

        $this->line($dryRun ? 'DRY RUN: no rows were deleted.' : 'EXECUTE: deleting matching geofence-only rows.');
        $this->table(['Scope', 'Rows'], [
            ['project_wialon_groups', count($summary['project_wialon_group_ids'])],
            ['wialon_group_ids', count($summary['wialon_group_ids'])],
            ['projects_with_only_excluded_groups', count($summary['project_ids'])],
            ['geofence_violation_report_rows', $summary['geofence_violation_report_rows']],
            ['geofence_violation_sync_items', $summary['geofence_violation_sync_items']],
            ['unit_foreign_geofence_intervals', $summary['unit_foreign_geofence_intervals']],
        ]);

        if ($dryRun) {
            return self::SUCCESS;
        }

        $deleted = [
            'geofence_violation_report_rows' => $this->violationRowsQuery($excludedGroups)->delete(),
            'geofence_violation_sync_items' => $this->syncItemsQuery($excludedGroups)->delete(),
            'unit_foreign_geofence_intervals' => $this->intervalsQuery($excludedGroups)->delete(),
        ];
        $excludedGroups->invalidateGeofenceCaches();

        $this->table(['Deleted scope', 'Rows'], collect($deleted)->map(fn (int $rows, string $scope): array => [$scope, $rows])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(GeofenceExcludedGroups $excludedGroups): array
    {
        return [
            'project_wialon_group_ids' => $excludedGroups->projectWialonGroupIds(),
            'wialon_group_ids' => $excludedGroups->wialonGroupIds(),
            'project_ids' => $excludedGroups->projectIdsWithOnlyExcludedGroups(),
            'geofence_violation_report_rows' => (clone $this->violationRowsQuery($excludedGroups))->count(),
            'geofence_violation_sync_items' => (clone $this->syncItemsQuery($excludedGroups))->count(),
            'unit_foreign_geofence_intervals' => (clone $this->intervalsQuery($excludedGroups))->count(),
        ];
    }

    private function violationRowsQuery(GeofenceExcludedGroups $excludedGroups): Builder
    {
        $groupIds = $excludedGroups->projectWialonGroupIds();
        $projectIds = $excludedGroups->projectIdsWithOnlyExcludedGroups();

        return GeofenceViolationReportRow::query()
            ->where('report_name', GeofenceViolationReportRow::REPORT_NAME)
            ->where(function (Builder $query) use ($groupIds, $projectIds): void {
                if ($groupIds !== []) {
                    $query->whereIn('project_wialon_group_id', $groupIds);
                }

                if ($projectIds !== []) {
                    $method = $groupIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('project_id', $projectIds);
                }

                if ($groupIds === [] && $projectIds === []) {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    private function syncItemsQuery(GeofenceExcludedGroups $excludedGroups): Builder
    {
        $groupIds = $excludedGroups->projectWialonGroupIds();
        $wialonGroupIds = $excludedGroups->wialonGroupIds();
        $projectIds = $excludedGroups->projectIdsWithOnlyExcludedGroups();

        return GeofenceViolationSyncItem::query()
            ->where(function (Builder $query) use ($groupIds, $wialonGroupIds, $projectIds): void {
                $hasCondition = false;

                if ($groupIds !== []) {
                    $query->whereIn('project_wialon_group_id', $groupIds);
                    $hasCondition = true;
                }

                if ($wialonGroupIds !== []) {
                    if ($hasCondition) {
                        $query->orWhereIn('wialon_group_id', $wialonGroupIds);
                    } else {
                        $query->whereIn('wialon_group_id', $wialonGroupIds);
                    }

                    $hasCondition = true;
                }

                if ($projectIds !== []) {
                    if ($hasCondition) {
                        $query->orWhereIn('project_id', $projectIds);
                    } else {
                        $query->whereIn('project_id', $projectIds);
                    }

                    $hasCondition = true;
                }

                if (! $hasCondition) {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    private function intervalsQuery(GeofenceExcludedGroups $excludedGroups): Builder
    {
        $wialonGroupIds = $excludedGroups->wialonGroupIds();
        $projectIds = $excludedGroups->projectIdsWithOnlyExcludedGroups();
        $projectWialonGroupIds = $excludedGroups->projectWialonGroupIds();

        return UnitForeignGeofenceInterval::query()
            ->where(function (Builder $query) use ($wialonGroupIds, $projectIds, $projectWialonGroupIds): void {
                $hasCondition = false;

                if ($wialonGroupIds !== []) {
                    $query->whereIn('source_group_id', $wialonGroupIds);
                    $hasCondition = true;
                }

                if ($projectIds !== []) {
                    if ($hasCondition) {
                        $query->orWhereIn('home_project_id', $projectIds);
                    } else {
                        $query->whereIn('home_project_id', $projectIds);
                    }

                    $hasCondition = true;
                }

                if ($projectWialonGroupIds !== [] || $wialonGroupIds !== []) {
                    $method = $hasCondition ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('unit', function (Builder $query) use ($projectWialonGroupIds, $wialonGroupIds): void {
                        $query->where(function (Builder $query) use ($projectWialonGroupIds, $wialonGroupIds): void {
                            if ($projectWialonGroupIds !== []) {
                                $query->whereIn('project_wialon_group_id', $projectWialonGroupIds);
                            }

                            if ($wialonGroupIds !== []) {
                                $method = $projectWialonGroupIds === [] ? 'whereIn' : 'orWhereIn';
                                $query->{$method}('matched_wialon_group_id', $wialonGroupIds);
                            }
                        });
                    });
                    $hasCondition = true;
                }

                if (! $hasCondition) {
                    $query->whereRaw('1 = 0');
                }
            });
    }
}
