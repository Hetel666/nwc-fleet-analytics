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
            $units = $wialon->getUnits(full: true);
        } catch (Throwable $exception) {
            Log::warning('Wialon unit sync failed', ['message' => $exception->getMessage()]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $typeCache = [];
        $unitMappings = $this->unitMappings($wialon);
        $count = 0;

        foreach ($units as $unit) {
            try {
                $unitId = (string) ($unit['id'] ?? '');
                if ($unitId === '') {
                    continue;
                }

                $type = $this->equipmentType($unit, $typeCache);
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

    /**
     * @param  array<string, EquipmentType>  $typeCache
     */
    private function equipmentType(array $unit, array &$typeCache): EquipmentType
    {
        $typeName = $this->equipmentTypeName($unit);
        $typeCache[$typeName] ??= EquipmentType::firstOrCreate(['name' => $typeName]);

        return $typeCache[$typeName];
    }

    private function equipmentTypeName(array $unit): string
    {
        $value = $this->profileField($unit, [
            'vehicle_class',
            'vehicle class',
            'equipment_type',
            'equipment type',
            'type',
            'тип тс',
            'тип транспорта',
            'texnika növü',
        ]);

        if ($value === null || $this->isOwnershipValue($value)) {
            return 'Imported';
        }

        $normalized = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $value)) ?? $value);

        if ($normalized === '') {
            return 'Imported';
        }

        return mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param  list<string>  $names
     */
    private function profileField(array $unit, array $names): ?string
    {
        $lookup = array_flip(array_map(fn (string $name): string => mb_strtolower($name), $names));

        foreach (['pflds', 'flds', 'aflds'] as $fieldSet) {
            foreach (($unit[$fieldSet] ?? []) as $field) {
                $name = mb_strtolower((string) ($field['n'] ?? ''));

                if (isset($lookup[$name])) {
                    $value = trim((string) ($field['v'] ?? ''));

                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }

    private function isOwnershipValue(string $value): bool
    {
        return in_array(mb_strtoupper(trim($value)), ['NWC', 'ICARE', 'İCARƏ'], true);
    }
}
