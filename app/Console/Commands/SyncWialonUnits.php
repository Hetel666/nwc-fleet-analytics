<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Services\WialonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWialonUnits extends Command
{
    protected $signature = 'fleet:sync-units';

    protected $description = 'Import and update equipment list from Wialon Hosting.';

    public function handle(WialonService $wialon): int
    {
        try {
            $units = $wialon->getUnits();
        } catch (Throwable $exception) {
            Log::warning('Wialon unit sync failed', ['message' => $exception->getMessage()]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $type = EquipmentType::firstOrCreate(['name' => 'Imported']);
        $count = 0;

        foreach ($units as $unit) {
            try {
                $unitId = (string) ($unit['id'] ?? '');
                if ($unitId === '') {
                    continue;
                }

                Equipment::updateOrCreate(
                    ['wialon_unit_id' => $unitId],
                    [
                        'name' => $unit['nm'] ?? $unit['name'] ?? 'Unit '.$unitId,
                        'equipment_type_id' => $type->id,
                        'ownership_type' => Equipment::OWNERSHIP_NWC,
                        'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
                        'planned_daily_hours' => 10,
                        'active' => true,
                        'last_position_json' => isset($unit['pos']) ? [
                            'lat' => $unit['pos']['y'] ?? null,
                            'lng' => $unit['pos']['x'] ?? null,
                            'speed' => $unit['pos']['s'] ?? null,
                        ] : null,
                        'last_synced_at' => now(),
                    ]
                );

                $count++;
            } catch (Throwable $exception) {
                Log::warning('Skipping Wialon unit during sync', [
                    'unit' => $unit['id'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Synced {$count} Wialon units.");

        return self::SUCCESS;
    }
}
