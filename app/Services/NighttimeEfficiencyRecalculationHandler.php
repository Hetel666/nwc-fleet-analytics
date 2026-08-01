<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\NighttimeEfficiencyDailyFact;
use App\Models\NighttimeEfficiencySyncRun;
use App\Models\NighttimeEfficiencySyncTask;
use App\Models\ProjectWialonGroup;
use App\Support\EfficiencyStatus;
use App\Support\FleetVehicleType;
use App\Support\NighttimeShiftWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class NighttimeEfficiencyRecalculationHandler
{
    public function __construct(
        private WialonService $wialon,
        private WialonNighttimeEfficiencyReportService $reports,
        private WialonNighttimeEfficiencyReportParser $parser,
        private WialonSessionManager $sessions,
    ) {}

    public function execute(HistoricalRecalculation $historicalRun, HistoricalRecalculationTask $historicalTask): int
    {
        $date = CarbonImmutable::parse($historicalTask->stat_date, $historicalRun->timezone);
        $lock = Cache::lock(
            'nighttime-efficiency:'.$historicalTask->project_id.':'.$date->toDateString(),
            (int) config('fleet.wialon.nighttime_efficiency_sync_lock_seconds', 1800),
        );

        if (! $lock->get()) {
            throw new RuntimeException('Nighttime efficiency synchronization is already running for this project and date.');
        }

        $run = $this->syncRun($historicalRun);
        $task = NighttimeEfficiencySyncTask::query()
            ->where('run_id', $run->id)
            ->where('project_id', $historicalTask->project_id)
            ->whereDate('shift_date', $date->toDateString())
            ->first() ?? new NighttimeEfficiencySyncTask([
                'run_id' => $run->id,
                'project_id' => $historicalTask->project_id,
                'shift_date' => $date->toDateString(),
            ]);
        $task->forceFill([
            'status' => 'running',
            'attempts' => (int) $task->attempts + 1,
            'started_at' => now($historicalRun->timezone),
            'completed_at' => null,
            'error_message' => null,
        ])->save();
        $this->refreshRun($run);

        try {
            $result = $this->synchronizeProjectDate($run, $task, $date);

            $task->forceFill([
                'wialon_group_id' => implode(',', $result['group_ids']),
                'status' => 'completed',
                'report_rows_received' => $result['report_rows_received'],
                'eligible_units_count' => $result['eligible_units_count'],
                'facts_saved_count' => $result['facts_saved_count'],
                'missing_units_count' => $result['missing_units_count'],
                'unmatched_report_rows' => $result['unmatched_report_rows'],
                'completed_at' => now($historicalRun->timezone),
                'error_message' => null,
            ])->save();
            $this->refreshRun($run);

            Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);

            return $result['facts_saved_count'];
        } catch (Throwable $exception) {
            $task->forceFill([
                'status' => 'failed',
                'completed_at' => now($historicalRun->timezone),
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();
            $this->refreshRun($run, $exception->getMessage());

            throw $exception;
        } finally {
            $this->sessions->close();
            optional($lock)->release();
        }
    }

    /** @return array<string, mixed> */
    private function synchronizeProjectDate(NighttimeEfficiencySyncRun $run, NighttimeEfficiencySyncTask $task, CarbonImmutable $date): array
    {
        $groups = ProjectWialonGroup::query()
            ->where('project_id', $task->project_id)
            ->when(
                Schema::hasColumn('project_wialon_groups', 'is_active'),
                fn (Builder $query): Builder => $query->where('is_active', true),
            )
            ->whereIn('ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])
            ->orderBy('ownership_type')
            ->get();

        if ($groups->isEmpty()) {
            throw new RuntimeException('The project has no active NWC or İcarə Wialon groups.');
        }

        $sid = $this->sessions->sid();
        $settings = $this->reports->settings();
        $facts = [];
        $unmatched = [];
        $reportRows = 0;
        $eligibleCount = 0;
        $missingCount = 0;

        foreach ($groups as $group) {
            $groupData = collect($this->wialon->getUnitGroups([$group->wialon_group_id]))->first();

            if (! is_array($groupData) || ! array_key_exists('u', $groupData)) {
                throw new RuntimeException("Could not load the full unit list for Wialon group {$group->wialon_group_id}.");
            }

            $memberIds = collect($groupData['u'])->map(fn ($id): string => (string) $id)->unique()->values();
            $equipment = Equipment::query()
                ->with(['type:id,name'])
                ->where('active', true)
                ->visibleInDashboard()
                ->where('project_id', $task->project_id)
                ->where('ownership_type', $group->ownership_type)
                ->whereIn('wialon_unit_id', $memberIds->all())
                ->get()
                ->filter(fn (Equipment $item): bool => in_array(
                    FleetVehicleType::normalize($item->type?->name),
                    FleetVehicleType::EFFICIENCY_TYPES,
                    true,
                ))
                ->keyBy(fn (Equipment $item): string => (string) $item->wialon_unit_id);

            $eligibleCount += $equipment->count();
            $window = NighttimeShiftWindow::forDate($date, $date->timezoneName);
            $shiftStartedAt = $window['start'];
            $shiftEndedAt = $window['end'];
            $report = $this->reports->execute($group, $shiftStartedAt, $shiftEndedAt, $sid);
            $parsed = $this->parser->parse($report);
            $reportRows += $parsed['rows_received'];
            $matchedIds = [];

            foreach ($parsed['records'] as $record) {
                $unitId = $record['wialon_unit_id'];
                $item = $unitId === null ? null : $equipment->get((string) $unitId);

                if (! $item instanceof Equipment) {
                    $unmatched[] = [
                        'project_id' => $task->project_id,
                        'shift_date' => $date->toDateString(),
                        'wialon_group_id' => (string) $group->wialon_group_id,
                        'wialon_unit_id' => $unitId,
                        'unit_name' => $record['unit_name'],
                        'reason' => $unitId === null ? 'missing_wialon_unit_id' : 'unit_not_eligible_or_not_mapped',
                        'raw_row_json' => json_encode($record['raw_row_json'], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    continue;
                }

                if (isset($facts[(string) $unitId])) {
                    throw new RuntimeException("Wialon unit {$unitId} is present in more than one project ownership group.");
                }

                $matchedIds[(string) $unitId] = true;
                $facts[(string) $unitId] = $this->factRow($run, $task, $group, $item, $date, $record, $settings);
            }

            foreach ($equipment as $unitId => $item) {
                if (isset($matchedIds[(string) $unitId])) {
                    continue;
                }

                $missingCount++;
                $facts[(string) $unitId] = $this->factRow($run, $task, $group, $item, $date, [
                    'unit_name' => $item->name,
                    'engine_hours_decimal' => 0,
                    'engine_seconds' => 0,
                    'engine_hours_raw' => null,
                    'started_at' => null,
                    'ended_at' => null,
                    'mileage_km' => null,
                    'mileage_raw' => null,
                    'raw_row_json' => null,
                ], $settings);
            }
        }

        DB::transaction(function () use ($task, $date, $facts, $unmatched): void {
            $unitIds = array_keys($facts);
            $stale = NighttimeEfficiencyDailyFact::query()
                ->where('project_id', $task->project_id)
                ->whereDate('shift_date', $date->toDateString());

            $unitIds === [] ? $stale->delete() : $stale->whereNotIn('wialon_unit_id', $unitIds)->delete();

            if ($facts !== []) {
                NighttimeEfficiencyDailyFact::query()->upsert(
                    array_values($facts),
                    ['shift_date', 'project_id', 'wialon_unit_id'],
                    [
                        'wialon_group_id', 'unit_name', 'vehicle_type', 'ownership',
                        'engine_hours_decimal', 'engine_seconds', 'engine_hours_raw',
                        'shift_started_at', 'shift_ended_at', 'started_at', 'ended_at',
                        'evening_engine_seconds', 'morning_engine_seconds', 'mileage_km', 'mileage_raw',
                        'efficiency_status', 'source_report_template_id', 'source_report_name',
                        'source_table_index', 'source_mode', 'source_parts_json',
                        'sync_run_id', 'sync_task_id', 'raw_row_json', 'updated_at',
                    ],
                );
            }

            DB::table('nighttime_efficiency_unmatched_rows')->where('task_id', $task->id)->delete();

            if ($unmatched !== []) {
                DB::table('nighttime_efficiency_unmatched_rows')->insert(
                    array_map(fn (array $row): array => ['task_id' => $task->id, ...$row], $unmatched),
                );
            }
        });

        return [
            'group_ids' => $groups->pluck('wialon_group_id')->map(fn ($id): string => (string) $id)->all(),
            'report_rows_received' => $reportRows,
            'eligible_units_count' => $eligibleCount,
            'facts_saved_count' => count($facts),
            'missing_units_count' => $missingCount,
            'unmatched_report_rows' => count($unmatched),
        ];
    }

    /** @return array<string, mixed> */
    private function factRow(
        NighttimeEfficiencySyncRun $run,
        NighttimeEfficiencySyncTask $task,
        ProjectWialonGroup $group,
        Equipment $equipment,
        CarbonImmutable $date,
        array $record,
        array $settings,
    ): array {
        $seconds = max(0, (int) $record['engine_seconds']);
        $hours = max(0, (float) $record['engine_hours_decimal']);

        $window = NighttimeShiftWindow::forDate($date, $date->timezoneName);

        return [
            'shift_date' => $date->toDateString(),
            'shift_started_at' => $window['start'],
            'shift_ended_at' => $window['end'],
            'project_id' => $group->project_id,
            'wialon_group_id' => (string) $group->wialon_group_id,
            'wialon_unit_id' => (string) $equipment->wialon_unit_id,
            'unit_name' => $record['unit_name'] ?: $equipment->name,
            'vehicle_type' => FleetVehicleType::label($equipment->type?->name),
            'ownership' => $group->ownership_type,
            'engine_hours_decimal' => number_format($hours, 2, '.', ''),
            'engine_seconds' => $seconds,
            'engine_hours_raw' => $record['engine_hours_raw'],
            'started_at' => $record['started_at'],
            'ended_at' => $record['ended_at'],
            'evening_engine_seconds' => null,
            'morning_engine_seconds' => null,
            'mileage_km' => $record['mileage_km'],
            'mileage_raw' => $record['mileage_raw'],
            'efficiency_status' => EfficiencyStatus::classify($seconds),
            'sync_run_id' => $run->id,
            'sync_task_id' => $task->id,
            'source_report_template_id' => $settings['template_id'],
            'source_report_name' => $settings['template_name'],
            'source_table_index' => $record['source_table_index'] ?? null,
            'source_mode' => 'single_cross_midnight',
            'source_parts_json' => json_encode([
                'interval_from' => $window['start']->toIso8601String(),
                'interval_to' => $window['end']->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
            'raw_row_json' => $record['raw_row_json'] === null ? null : json_encode($record['raw_row_json'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function syncRun(HistoricalRecalculation $historicalRun): NighttimeEfficiencySyncRun
    {
        $run = NighttimeEfficiencySyncRun::query()->updateOrCreate(
            ['historical_recalculation_id' => $historicalRun->id],
            [
                'date_from' => $historicalRun->date_from,
                'date_to' => $historicalRun->date_to,
                'status' => 'running',
                'started_at' => $historicalRun->started_at ?: now($historicalRun->timezone),
                'completed_at' => null,
                'created_by' => $historicalRun->requested_by,
                'error_message' => null,
            ],
        );

        foreach ($historicalRun->tasks()->where('operation', HistoricalRecalculation::OPERATION_FETCH)->get() as $task) {
            $exists = NighttimeEfficiencySyncTask::query()
                ->where('run_id', $run->id)
                ->where('project_id', $task->project_id)
                ->whereDate('shift_date', $task->stat_date->toDateString())
                ->exists();

            if (! $exists) {
                NighttimeEfficiencySyncTask::query()->create([
                    'run_id' => $run->id,
                    'project_id' => $task->project_id,
                    'shift_date' => $task->stat_date->toDateString(),
                    'status' => 'pending',
                ]);
            }
        }

        return $run;
    }

    private function refreshRun(NighttimeEfficiencySyncRun $run, ?string $error = null): void
    {
        $counts = $run->tasks()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $run->tasks()->count();
        $pending = (int) ($counts['pending'] ?? 0);
        $running = (int) ($counts['running'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);
        $terminal = $total > 0 && $pending === 0 && $running === 0;

        $run->forceFill([
            'status' => $terminal ? ($failed > 0 ? 'failed' : 'completed') : 'running',
            'total_tasks' => $total,
            'pending_tasks' => $pending,
            'running_tasks' => $running,
            'completed_tasks' => $completed,
            'failed_tasks' => $failed,
            'completed_at' => $terminal ? now() : null,
            'error_message' => $error === null ? $run->error_message : mb_substr($error, 0, 4000),
        ])->save();
    }
}
