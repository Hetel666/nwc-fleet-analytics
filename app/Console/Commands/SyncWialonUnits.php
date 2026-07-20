<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Services\ForeignProjectGeofenceMonitoringService;
use App\Services\WialonService;
use App\Services\WialonGroupClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWialonUnits extends Command
{
    protected $signature = 'fleet:sync-units';

    protected $description = 'Import and update equipment list from Wialon Hosting.';

    public function handle(
        WialonService $wialon,
        WialonGroupClassificationService $classification,
        ForeignProjectGeofenceMonitoringService $foreignGeofences
    ): int {
        try {
            $units = $wialon->getUnits(full: true);
        } catch (Throwable $exception) {
            Log::warning('Wialon unit sync failed', ['message' => $exception->getMessage()]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $typeCache = [];
        $unitGroups = $this->unitGroups($wialon, $classification);
        $unitMappings = $unitGroups['mappings'];
        $excludedUnitIds = $unitGroups['excluded'];
        $exclusionsSynced = (bool) $unitGroups['exclusions_synced'];
        $count = 0;

        foreach ($units as $unit) {
            try {
                $unitId = (string) ($unit['id'] ?? '');
                if ($unitId === '') {
                    continue;
                }

                $type = $this->equipmentType($unit, $typeCache);
                $equipment = Equipment::firstOrNew(['wialon_unit_id' => $unitId]);
                $position = isset($unit['pos']) ? [
                    'lat' => $unit['pos']['y'] ?? null,
                    'lng' => $unit['pos']['x'] ?? null,
                    'speed' => $unit['pos']['s'] ?? null,
                    'time' => isset($unit['pos']['t']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $unit['pos']['t'])->toDateTimeString() : null,
                ] : null;

                $equipment->fill([
                    'name' => $unit['nm'] ?? $unit['name'] ?? 'Unit '.$unitId,
                    'equipment_type_id' => $type->id,
                    'calculation_mode' => Equipment::MODE_ENGINE_HOURS,
                    'planned_daily_hours' => 10,
                    'active' => true,
                    'last_position_json' => $position,
                    'last_synced_at' => now(),
                ]);

                if ($exclusionsSynced) {
                    $excluded = isset($excludedUnitIds[$unitId]);
                    $equipment->fill([
                        'excluded_from_dashboard' => $excluded,
                        'dashboard_exclusion_reason' => $excluded ? Equipment::DASHBOARD_EXCLUSION_GENERATOR_GROUP : null,
                    ]);
                }

                $mapping = $unitMappings[$unitId] ?? null;
                $ownershipType = $mapping['ownership_type'] ?? null;

                $equipment->fill([
                    'project_id' => $mapping['project_id'] ?? null,
                    'project_wialon_group_id' => $mapping['project_wialon_group_id'] ?? null,
                    'matched_wialon_group_id' => $mapping['matched_group_id'] ?? null,
                    'matched_wialon_group_name' => $mapping['matched_group_name'] ?? null,
                ]);

                if ($ownershipType !== null) {
                    $equipment->ownership_type = $ownershipType;
                } elseif (! $equipment->exists) {
                    $equipment->ownership_type = Equipment::OWNERSHIP_NWC;
                }

                $equipment->save();
                $equipment->loadMissing('type');
                $foreignGeofences->processUnitPosition($equipment, $position);

                $count++;
            } catch (Throwable $exception) {
                Log::warning('Skipping Wialon unit during sync', [
                    'unit' => $unit['id'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);

        $this->info("Synced {$count} Wialon units.");

        return self::SUCCESS;
    }

    private function unitGroups(WialonService $wialon, WialonGroupClassificationService $classification): array
    {
        try {
            $groups = $wialon->getUnitGroups($classification->classificationGroupIds());
        } catch (Throwable $exception) {
            Log::warning('Wialon group sync failed', ['message' => $exception->getMessage()]);

            return [
                'mappings' => [],
                'excluded' => [],
                'exclusions_synced' => false,
            ];
        }

        $unitMappings = [];
        $unitGroupRefs = [];
        $excludedUnitIds = [];

        foreach ($groups as $group) {
            $groupId = (string) ($group['id'] ?? '');
            $groupName = $group['nm'] ?? $group['name'] ?? '';
            $isGeneratorGroup = Equipment::isGeneratorGroup($groupName);

            foreach ($this->unitIds($group) as $unitId) {
                if ($isGeneratorGroup) {
                    $excludedUnitIds[$unitId] = true;
                }

                $unitGroupRefs[$unitId][] = [
                    'id' => $groupId,
                    'name' => (string) $groupName,
                ];
            }
        }

        foreach ($unitGroupRefs as $unitId => $unitGroups) {
            $classified = $classification->classifyUnit($unitGroups, $unitId);

            if ($classified['conflict']) {
                $unitMappings[$unitId] = [
                    'project_id' => null,
                    'project_wialon_group_id' => null,
                    'ownership_type' => null,
                    'matched_group_id' => null,
                    'matched_group_name' => null,
                ];

                continue;
            }

            if ($classified['ownership'] !== null) {
                $unitMappings[$unitId] = [
                    'project_id' => $classified['project_id'],
                    'project_wialon_group_id' => $classified['project_wialon_group_id'],
                    'ownership_type' => $classified['ownership'],
                    'matched_group_id' => $classified['matched_group_id'],
                    'matched_group_name' => $classified['matched_group_name'],
                ];
            }
        }

        return [
            'mappings' => $unitMappings,
            'excluded' => $excludedUnitIds,
            'exclusions_synced' => true,
        ];
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
