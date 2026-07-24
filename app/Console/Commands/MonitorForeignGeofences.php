<?php

namespace App\Console\Commands;

use App\Contracts\UnitPositionSource;
use App\Data\UnitPositionData;
use App\Models\Equipment;
use App\Services\ForeignProjectGeofenceMonitoringService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorForeignGeofences extends Command
{
    protected $signature = 'fleet:monitor-foreign-geofences
        {--equipment= : Equipment local ID or Wialon unit ID}
        {--project= : Project database ID}
        {--limit= : Maximum equipment rows to process}
        {--dry-run : Validate positions without mutating intervals}
        {--force : Run even when monitoring feature flag is disabled}';

    protected $description = 'Update current foreign-project geofence intervals from locally stored unit positions.';

    public function handle(UnitPositionSource $positions, ForeignProjectGeofenceMonitoringService $monitoring): int
    {
        if (! $this->monitoringEnabled() && ! $this->option('force')) {
            $this->line('Foreign geofence monitoring is disabled.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(
            'fleet:monitor-foreign-geofences',
            max(30, (int) config('fleet.foreign_geofence.monitoring_lock_seconds', 240))
        );

        if (! $lock->get()) {
            $this->line('Foreign geofence monitoring is already running.');

            return self::SUCCESS;
        }

        try {
            $summary = $this->runWithLock($positions, $monitoring);
        } catch (Throwable $exception) {
            Log::error('Foreign geofence monitoring failed', [
                'message' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }

        $this->printSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return array<string, int|bool>
     */
    private function runWithLock(UnitPositionSource $positions, ForeignProjectGeofenceMonitoringService $monitoring): array
    {
        $summary = $this->emptySummary();
        $batchSize = $this->batchSize();
        $remaining = $this->limit();
        $query = $this->equipmentQuery();

        $query->chunkById($batchSize, function (Collection $equipment) use ($positions, $monitoring, &$summary, &$remaining): bool {
            if ($remaining !== null) {
                $equipment = $equipment->take($remaining)->values();
            }

            if ($equipment->isEmpty()) {
                return false;
            }

            $summary['batches']++;
            $summary['candidates'] += $equipment->count();
            $positionByEquipmentId = $positions->latestPositionsFor($equipment);

            foreach ($equipment as $unit) {
                $this->processUnit($unit, $positionByEquipmentId[(int) $unit->id] ?? null, $monitoring, $summary);
            }

            if ($remaining !== null) {
                $remaining -= $equipment->count();

                return $remaining > 0;
            }

            return true;
        });

        return $summary;
    }

    /**
     * @param  array<string, int|bool>  $summary
     */
    private function processUnit(
        Equipment $unit,
        ?UnitPositionData $position,
        ForeignProjectGeofenceMonitoringService $monitoring,
        array &$summary
    ): void {
        if (! $position instanceof UnitPositionData) {
            $summary['missing_position']++;

            return;
        }

        $payload = $position->toMonitoringPayload();
        $positionAt = $this->positionTime($payload);

        if ($positionAt === null || ! is_numeric($payload['lat'] ?? null) || ! is_numeric($payload['lng'] ?? null)) {
            $summary['invalid_position']++;

            return;
        }

        if ($positionAt->gt(now(config('app.timezone'))->addSeconds($this->futureSkewSeconds()))) {
            $summary['future_position']++;

            return;
        }

        if ($positionAt->lt(now(config('app.timezone'))->subMinutes($this->staleAfterMinutes()))) {
            $summary['stale_position']++;

            return;
        }

        $summary['positions']++;

        if ($this->option('dry-run')) {
            $summary['dry_run'] = true;
            $summary['processed']++;

            return;
        }

        try {
            $interval = $monitoring->processUnitPosition($unit, $payload);
            $summary['processed']++;

            if ($interval !== null) {
                $summary['active_intervals']++;
            }
        } catch (Throwable $exception) {
            $summary['failed']++;
            Log::warning('Foreign geofence monitoring skipped unit after failure', [
                'equipment_id' => $unit->id,
                'wialon_unit_id' => $unit->wialon_unit_id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function equipmentQuery(): Builder
    {
        return Equipment::query()
            ->with(['type:id,name', 'project:id,name', 'projectWialonGroup:id,wialon_group_id,name,project_id,ownership_type'])
            ->where('active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->whereNotNull('project_id')
            ->whereNotNull('wialon_unit_id')
            ->when(filled($this->option('equipment')), function (Builder $query): void {
                $equipment = trim((string) $this->option('equipment'));
                $query->where(function (Builder $query) use ($equipment): void {
                    $query->whereKey($equipment)
                        ->orWhere('wialon_unit_id', $equipment);
                });
            })
            ->when(filled($this->option('project')), fn (Builder $query) => $query->where('project_id', (int) $this->option('project')))
            ->orderBy('id');
    }

    private function positionTime(array $position): ?Carbon
    {
        if (! filled($position['time'] ?? null)) {
            return null;
        }

        try {
            return Carbon::parse($position['time'], config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function monitoringEnabled(): bool
    {
        return (bool) config('fleet.foreign_geofence.monitoring_enabled', false);
    }

    private function batchSize(): int
    {
        return max(1, (int) config('fleet.foreign_geofence.monitoring_batch_size', 100));
    }

    private function staleAfterMinutes(): int
    {
        return max(1, (int) config('fleet.foreign_geofence.stale_after_minutes', 30));
    }

    private function futureSkewSeconds(): int
    {
        return max(0, (int) config('fleet.foreign_geofence.monitoring_future_skew_seconds', 300));
    }

    private function limit(): ?int
    {
        if (! filled($this->option('limit'))) {
            return null;
        }

        return max(1, (int) $this->option('limit'));
    }

    /**
     * @return array<string, int|bool>
     */
    private function emptySummary(): array
    {
        return [
            'dry_run' => false,
            'batches' => 0,
            'candidates' => 0,
            'positions' => 0,
            'processed' => 0,
            'active_intervals' => 0,
            'missing_position' => 0,
            'invalid_position' => 0,
            'future_position' => 0,
            'stale_position' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @param  array<string, int|bool>  $summary
     */
    private function printSummary(array $summary): void
    {
        $this->line('Foreign geofence monitoring summary:');

        foreach ($summary as $key => $value) {
            $this->line("{$key}: ".($value === true ? 'yes' : ($value === false ? 'no' : (string) $value)));
        }
    }
}
