<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\ProjectWialonGroup;
use App\Services\WialonService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
        $unitMappings = $this->unitMappings($wialon);
        $count = 0;

        foreach ($units as $unit) {
            try {
                $unitId = (string) ($unit['id'] ?? '');
                if ($unitId === '') {
                    continue;
                }

                $equipment = Equipment::firstOrNew(['wialon_unit_id' => $unitId]);
                $equipment->fill([
                    'name' => $unit['nm'] ?? $unit['name'] ?? 'Unit '.$unitId,
                    'equipment_type_id' => $type->id,
                    'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
                    'planned_daily_hours' => 10,
                    'active' => true,
                    'last_position_json' => isset($unit['pos']) ? [
                        'lat' => $unit['pos']['y'] ?? null,
                        'lng' => $unit['pos']['x'] ?? null,
                        'speed' => $unit['pos']['s'] ?? null,
                    ] : null,
                    'last_synced_at' => now(),
                ]);

                $mapping = $unitMappings[$unitId] ?? null;
                if ($mapping !== null) {
                    $equipment->fill([
                        'project_id' => $mapping['project_id'],
                        'ownership_type' => $mapping['ownership_type'],
                    ]);
                } elseif (! $equipment->exists) {
                    $equipment->ownership_type = Equipment::OWNERSHIP_NWC;
                }

                $equipment->save();

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

    private function unitMappings(WialonService $wialon): array
    {
        $mappings = ProjectWialonGroup::query()->get()->keyBy('wialon_group_id');

        if ($mappings->isEmpty()) {
            return [];
        }

        try {
            $groups = $wialon->getUnitGroups($mappings->keys()->all());
        } catch (Throwable $exception) {
            Log::warning('Wialon group sync failed', ['message' => $exception->getMessage()]);

            return [];
        }

        $unitMappings = [];

        foreach ($groups as $group) {
            $mapping = $mappings->get((string) ($group['id'] ?? ''));
            if (! $mapping instanceof ProjectWialonGroup) {
                continue;
            }

            foreach ($this->unitIds($group) as $unitId) {
                $unitMappings[$unitId] ??= [
                    'project_id' => $mapping->project_id,
                    'ownership_type' => $mapping->ownership_type,
                ];
            }
        }

        return $unitMappings;
    }

    private function unitIds(array $group): Collection
    {
        return collect($group['u'] ?? [])
            ->map(fn ($unit): string => is_array($unit) ? (string) ($unit['id'] ?? '') : (string) $unit)
            ->filter()
            ->values();
    }
}
