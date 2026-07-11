<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\DailyUnitAggregate;
use App\Services\WialonService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDailyStats extends Command
{
    protected $signature = 'fleet:sync-daily {--date= : Date in YYYY-MM-DD format}';

    protected $description = 'Calculate and store daily fleet statistics from Wialon messages.';

    public function handle(WialonService $wialon): int
    {
        $date = Carbon::parse($this->option('date') ?: now()->subDay()->toDateString());
        $count = 0;

        Equipment::query()->where('active', true)->orderBy('id')->chunk(50, function ($equipment) use ($wialon, $date, &$count): void {
            foreach ($equipment as $item) {
                try {
                    $stats = $wialon->calculateUnitDailyData($item->wialon_unit_id, $date, $item->calculation_mode);
                    $utilization = $item->planned_daily_hours > 0
                        ? min(100, ($stats['worked_hours'] / (float) $item->planned_daily_hours) * 100)
                        : 0;

                    $dailyStat = EquipmentDailyStat::updateOrCreate(
                        ['stat_date' => $date->toDateString(), 'equipment_id' => $item->id],
                        [
                            'project_id' => $item->project_id,
                            'ownership_type' => $item->ownership_type,
                            'worked_hours' => $stats['worked_hours'],
                            'distance_km' => $stats['distance_km'],
                            'utilization_percent' => round($utilization, 2),
                            'first_message_at' => $stats['first_message_at'],
                            'last_message_at' => $stats['last_message_at'],
                            'calculation_source' => $stats['calculation_source'],
                            'calculation_status' => $stats['calculation_status'],
                        ]
                    );

                    DailyUnitAggregate::updateOrCreate(
                        [
                            'date' => $dailyStat->stat_date->toDateString(),
                            'unit_id' => $item->wialon_unit_id,
                        ],
                        [
                            'equipment_id' => $item->id,
                            'project_id' => $item->project_id,
                            'equipment_type_id' => $item->equipment_type_id,
                            'ownership_type' => $item->ownership_type,
                            'engine_hours' => $dailyStat->worked_hours,
                            'mileage' => $dailyStat->distance_km,
                            'geofence_outside_hours' => round(((float) $dailyStat->outside_geofence_minutes) / 60, 2),
                        ]
                    );

                    if (! empty($stats['last_position'])) {
                        $item->update([
                            'last_position_json' => $stats['last_position'],
                            'last_synced_at' => now(),
                        ]);
                    }

                    $count++;
                } catch (Throwable $exception) {
                    Log::warning('Daily Wialon stats failed for unit', [
                        'equipment_id' => $item->id,
                        'wialon_unit_id' => $item->wialon_unit_id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Calculated daily stats for {$count} equipment records.");

        return self::SUCCESS;
    }
}
