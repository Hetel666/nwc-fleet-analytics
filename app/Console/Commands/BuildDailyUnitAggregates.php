<?php

namespace App\Console\Commands;

use App\Models\DailyUnitAggregate;
use App\Models\EquipmentDailyStat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildDailyUnitAggregates extends Command
{
    protected $signature = 'fleet:aggregate-daily
        {--date= : Single date in YYYY-MM-DD format}
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}';

    protected $description = 'Build daily unit aggregate rows for dashboard and export queries.';

    public function handle(): int
    {
        $from = $this->option('date') ?: $this->option('from') ?: now()->subDay()->toDateString();
        $to = $this->option('date') ?: $this->option('to') ?: $from;
        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to)->toDateString();

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $count = 0;

        EquipmentDailyStat::query()
            ->with('equipment:id,wialon_unit_id,equipment_type_id')
            ->whereBetween('stat_date', [$fromDate, $toDate])
            ->orderBy('id')
            ->chunkById(500, function ($stats) use (&$count): void {
                foreach ($stats as $stat) {
                    $equipment = $stat->equipment;

                    if (! $equipment || ! $equipment->wialon_unit_id) {
                        continue;
                    }

                    DailyUnitAggregate::updateOrCreate(
                        [
                            'date' => $stat->stat_date->toDateString(),
                            'unit_id' => $equipment->wialon_unit_id,
                        ],
                        [
                            'equipment_id' => $stat->equipment_id,
                            'project_id' => $stat->project_id,
                            'equipment_type_id' => $equipment->equipment_type_id,
                            'ownership_type' => $stat->ownership_type,
                            'engine_hours' => $stat->worked_hours,
                            'mileage' => $stat->distance_km,
                            'geofence_outside_hours' => round(((float) $stat->outside_geofence_minutes) / 60, 2),
                        ]
                    );

                    $count++;
                }
            });

        $this->info("Built {$count} daily aggregate rows for {$fromDate} - {$toDate}.");

        return self::SUCCESS;
    }
}
