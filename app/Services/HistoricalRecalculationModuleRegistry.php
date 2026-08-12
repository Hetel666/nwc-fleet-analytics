<?php

namespace App\Services;

use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\GeofenceViolationSyncItem;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class HistoricalRecalculationModuleRegistry
{
    public function __construct(
        private WialonReportStatsSyncService $dailyStats,
        private EfficiencyRecalculationHandler $efficiency,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $queue = (string) config('historical_recalculation.queue', 'historical-recalculations');
        $moduleQueues = (array) config('historical_recalculation.module_queues', []);
        $monthlyQueue = (string) ($moduleQueues[HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY] ?? $queue);

        return [
            HistoricalRecalculation::SECTION_DAILY_AVERAGES => [
                'label' => 'Orta göstəricilər',
                'handler' => 'executeDailyAverages',
                'service' => WialonReportStatsSyncService::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['equipment_daily_stats', 'daily_unit_aggregates'],
                'aliases' => ['average_engine_hours', 'average_mileage'],
            ],
            HistoricalRecalculation::SECTION_EFFICIENCY => [
                'label' => 'Effektivlik',
                'handler' => 'executeEfficiency',
                'service' => EfficiencyRecalculationHandler::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => [
                    'efficiency_daily_facts',
                    'efficiency_sync_runs',
                    'efficiency_sync_tasks',
                    'equipment_daily_stats',
                    'daily_unit_aggregates',
                    'engine_hours_report_unit_days',
                    'wialon_report_sync_items',
                ],
                'aliases' => [],
            ],
            HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY => [
                'label' => 'Aylıq effektivlik',
                'handler' => 'executeMonthlyEfficiency',
                'service' => 'App\\Console\\Commands\\SyncMonthlyEfficiencyObjects',
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $monthlyQueue,
                'result_tables' => ['monthly_efficiency_unit_geofence_facts'],
                'aliases' => [],
            ],
            HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE => [
                'label' => 'Geofence Transferləri',
                'handler' => 'executeGeofenceOutside',
                'service' => WialonGeozonReportService::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['unit_foreign_geofence_intervals'],
                'aliases' => ['geofence_transfers'],
            ],
            HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS => [
                'label' => 'Geofence Pozuntuları',
                'handler' => 'executeGeofenceViolations',
                'service' => GeofenceViolationReportImporter::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['geofence_violation_sync_items', 'geofence_violation_report_rows'],
                'aliases' => [],
            ],
        ];
    }

    public function canonicalSection(string $section): string
    {
        foreach ($this->definitions() as $code => $definition) {
            if ($section === $code || in_array($section, $definition['aliases'], true)) {
                return $code;
            }
        }

        if ($this->isDisabledLegacySection($section)) {
            return $section;
        }

        throw new InvalidArgumentException("Unsupported historical recalculation module: {$section}");
    }

    /** @return array<string, mixed> */
    public function definition(string $section): array
    {
        $section = $this->canonicalSection($section);

        return $this->definitions()[$section] ?? $this->disabledLegacyDefinition($section);
    }

    public function execute(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $this->assertSelectedProjectTaskScope($run, $task);

        $definition = $this->definition((string) $run->dashboard_section);
        $handler = $definition['handler'];

        return $this->{$handler}($run, $task);
    }

    private function assertSelectedProjectTaskScope(
        HistoricalRecalculation $run,
        HistoricalRecalculationTask $task
    ): void {
        if ($run->scope !== HistoricalRecalculation::SCOPE_SELECTED_PROJECTS) {
            return;
        }

        $projectIds = collect($run->project_ids ?? [])
            ->map(fn (mixed $projectId): int => (int) $projectId)
            ->filter()
            ->unique()
            ->values();

        if ($task->project_id === null || ! $projectIds->contains((int) $task->project_id)) {
            throw new RuntimeException(sprintf(
                'Selected-project historical task %d has an invalid project scope.',
                (int) $task->id
            ));
        }
    }

    private function executeDailyAverages(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $result = $this->dailyStats->syncDailyEngineHoursReport([
            'date_from' => $task->stat_date->toDateString(),
            'date_to' => $task->stat_date->toDateString(),
            'project_id' => $task->project_id,
            'ownership_type' => $task->ownership_type,
        ], (bool) $run->force);

        return (int) ($result['equipment_count'] ?? 0);
    }

    private function executeEfficiency(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        return $this->efficiency->execute($run, $task);
    }

    private function executeMonthlyEfficiency(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $date = $task->stat_date->toDateString();

        $this->runArtisanOrFail('monthly-efficiency:sync-objects', array_filter([
            '--from' => $date,
            '--to' => $date,
            '--project' => $task->project_id,
            '--force' => (bool) $run->force,
            '--unit-chunk' => 10,
            '--flush-rows' => 100,
            '--historical-task-id' => $task->id,
        ], $this->hasValue(...)));

        $query = DB::table('monthly_efficiency_unit_geofence_facts')
            ->where('stat_date', $date)
            ->where('segment_type', 'total');

        if ($task->project_id) {
            $query->where('project_id', $task->project_id);
        }

        return $query
            ->distinct('wialon_unit_id')
            ->count('wialon_unit_id');
    }

    private function executeGeofenceOutside(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $date = $task->stat_date->toDateString();

        $this->runArtisanOrFail('fleet:sync-geozon-api', array_filter([
            '--from' => $date.' 00:00:00',
            '--to' => $date.' 23:59:59',
            '--project' => $task->project_id,
            '--force' => (bool) $run->force,
        ], $this->hasValue(...)));

        return 0;
    }

    private function executeGeofenceViolations(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $from = $run->date_from->toDateString().' 00:00:00';
        $to = $run->date_to->toDateString().' 23:59:59';

        $this->runArtisanOrFail('fleet:sync-geofence-violations-report', array_filter([
            '--from' => $from,
            '--to' => $to,
            '--project' => $task->project_id,
            '--force' => (bool) $run->force,
        ], $this->hasValue(...)));

        return (int) GeofenceViolationSyncItem::query()
            ->where('project_id', $task->project_id)
            ->where('report_period_from', Carbon::parse($from, $run->timezone))
            ->where('report_period_to', Carbon::parse($to, $run->timezone))
            ->where('status', GeofenceViolationSyncItem::STATUS_COMPLETED)
            ->sum('imported_rows');
    }

    private function executeDisabledLegacyModule(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        throw new RuntimeException(sprintf(
            'Dashboard module %s has been removed and cannot be recalculated.',
            (string) $run->dashboard_section
        ));
    }

    /** @return array<string, mixed> */
    private function disabledLegacyDefinition(string $section): array
    {
        if (! $this->isDisabledLegacySection($section)) {
            throw new InvalidArgumentException("Unsupported historical recalculation module: {$section}");
        }

        return [
            'label' => $section,
            'handler' => 'executeDisabledLegacyModule',
            'service' => null,
            'job' => RunHistoricalRecalculationTaskJob::class,
            'queue' => (string) config('historical_recalculation.queue', 'historical-recalculations'),
            'result_tables' => [],
            'aliases' => [],
            'disabled' => true,
        ];
    }

    private function isDisabledLegacySection(string $section): bool
    {
        return in_array($section, [
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
            HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
        ], true);
    }

    private function runArtisanOrFail(string $command, array $parameters): void
    {
        $exitCode = Artisan::call($command, $parameters);

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());
            $message = "Command {$command} failed with exit code {$exitCode}.";

            throw new RuntimeException($output === '' ? $message : $message.' '.$output);
        }
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
