<?php

namespace App\Services;

use App\Jobs\RunHistoricalRecalculationTaskJob;
use App\Models\GeofenceViolationSyncItem;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use RuntimeException;

class HistoricalRecalculationModuleRegistry
{
    public function __construct(
        private WialonReportStatsSyncService $dailyStats,
        private EfficiencyRecalculationHandler $efficiency,
        private DaytimeEfficiencyRecalculationHandler $daytimeEfficiency,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $queue = (string) config('historical_recalculation.queue', 'historical-recalculations');

        return [
            HistoricalRecalculation::SECTION_DAILY_AVERAGES => [
                'label' => 'Orta göstəricilər',
                'handler' => 'executeDailyAverages',
                'service' => WialonReportStatsSyncService::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['equipment_daily_stats', 'daily_unit_aggregates'],
                'aliases' => [],
            ],
            HistoricalRecalculation::SECTION_EFFICIENCY => [
                'label' => 'Effektivlik',
                'handler' => 'executeEfficiency',
                'service' => EfficiencyRecalculationHandler::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['efficiency_daily_facts', 'efficiency_sync_runs', 'efficiency_sync_tasks'],
                'aliases' => [],
            ],
            HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY => [
                'label' => 'Effektivlik gündüz',
                'handler' => 'executeDaytimeEfficiency',
                'service' => DaytimeEfficiencyRecalculationHandler::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => [
                    'daytime_efficiency_daily_facts',
                    'daytime_efficiency_sync_runs',
                    'daytime_efficiency_sync_tasks',
                ],
                'aliases' => [],
            ],
            HistoricalRecalculation::SECTION_TOP_WORKING_UNITS => [
                'label' => 'Top 20',
                'handler' => 'executeTopWorkingUnits',
                'service' => EngineHoursTop20SyncService::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['engine_hours_report_unit_days', 'wialon_report_sync_items'],
                'aliases' => [],
            ],
            HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE => [
                'label' => 'Geofence Transferləri',
                'handler' => 'executeGeofenceOutside',
                'service' => WialonGeozonReportService::class,
                'job' => RunHistoricalRecalculationTaskJob::class,
                'queue' => $queue,
                'result_tables' => ['unit_foreign_geofence_intervals'],
                'aliases' => [],
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

        throw new InvalidArgumentException("Unsupported historical recalculation module: {$section}");
    }

    /** @return array<string, mixed> */
    public function definition(string $section): array
    {
        return $this->definitions()[$this->canonicalSection($section)];
    }

    public function execute(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $definition = $this->definition((string) $run->dashboard_section);
        $handler = $definition['handler'];

        return $this->{$handler}($run, $task);
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

    private function executeTopWorkingUnits(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        $date = $task->stat_date->toDateString();

        $this->runArtisanOrFail('fleet:sync-engine-hours-report', array_filter([
            '--date' => $date,
            '--project' => $task->project_id,
            '--ownership' => $task->ownership_type,
            '--force' => (bool) $run->force,
            '--limit' => 50,
        ], $this->hasValue(...)));

        return 0;
    }

    private function executeEfficiency(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        return $this->efficiency->execute($run, $task);
    }

    private function executeDaytimeEfficiency(HistoricalRecalculation $run, HistoricalRecalculationTask $task): int
    {
        return $this->daytimeEfficiency->execute($run, $task);
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
