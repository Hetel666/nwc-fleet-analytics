<?php

namespace App\Services;

use App\Models\DaytimeEfficiencyFact;
use App\Models\Equipment;
use App\Models\ProjectWialonGroup;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class DaytimeEfficiencySyncService
{
    public function __construct(
        private WialonShiftReportService $reports,
        private WialonDaytimeEfficiencyReportParser $parser,
        private DaytimeEfficiencyClassifier $classifier,
        private FleetShiftDailyStatsSyncService $equipmentSource,
    ) {}

    /** @return array<string, mixed> */
    public function syncGroup(ProjectWialonGroup $group, CarbonImmutable $date): array
    {
        $equipment = $this->equipmentSource->equipmentForGroup($group);

        if ($equipment->isEmpty()) {
            return $this->emptyResult();
        }

        $report = $this->reports->executeSourceForGroup(
            (string) config('daytime_efficiency.report_source', 'daytime'),
            $group,
            $date->startOfDay(),
            $date->endOfDay()
        );

        if (! array_key_exists('tables', $report)) {
            throw new RuntimeException('Wialon daytime report returned no table metadata. Existing facts were not changed.');
        }

        $parsed = $this->parser->parse($report);
        $recordMap = $this->recordMap($parsed['records']);
        $matchedKeys = [];
        $now = CarbonImmutable::now($this->timezone());
        $rows = [];
        $categoryCounts = [];
        $detailCounts = [];

        foreach ($equipment as $item) {
            $record = $this->findRecord($recordMap, $item);

            if ($record !== null) {
                $matchedKeys[] = $record['_match_key'];
            }

            $classification = $this->classifier->classify(
                $record['engine_hours_decimal'] ?? null,
                $record !== null,
                (bool) ($record['parse_succeeded'] ?? false),
                (bool) ($record['raw_value_is_empty'] ?? true),
            );
            $categoryCounts[$classification['category']] = ($categoryCounts[$classification['category']] ?? 0) + 1;
            $detailCounts[$classification['detail_status']] = ($detailCounts[$classification['detail_status']] ?? 0) + 1;

            $rows[] = $this->factRow($group, $item, $date, $record, $classification, $report, $now);
        }

        DB::transaction(function () use ($group, $date, $rows): void {
            DaytimeEfficiencyFact::query()
                ->where('fact_date', $date->toDateString())
                ->where('project_id', $group->project_id)
                ->where('ownership_type', $group->ownership_type)
                ->delete();

            DaytimeEfficiencyFact::query()->upsert(
                $rows,
                ['fact_date', 'equipment_id'],
                array_values(array_diff(array_keys($rows[0]), ['fact_date', 'equipment_id', 'created_at']))
            );
        });

        $unmatched = collect($parsed['records'])
            ->reject(fn (array $record): bool => in_array($record['_match_key'], $matchedKeys, true))
            ->values();

        if ($unmatched->isNotEmpty() || $parsed['duplicates'] !== [] || $parsed['malformed_rows'] > 0) {
            Log::warning('Wialon daytime efficiency report contains diagnostic rows', [
                'date' => $date->toDateString(),
                'group_id' => $group->wialon_group_id,
                'unmatched_units' => $unmatched->pluck('unit_name')->take(50)->all(),
                'duplicates' => $parsed['duplicates'],
                'malformed_rows' => $parsed['malformed_rows'],
            ]);
        }

        Cache::forever('daytime-efficiency:data-version', ((int) Cache::get('daytime-efficiency:data-version', 1)) + 1);

        return [
            'equipment_count' => $equipment->count(),
            'report_rows' => count($parsed['records']),
            'saved_rows' => count($rows),
            'unmatched_rows' => $unmatched->count(),
            'duplicate_rows' => count($parsed['duplicates']),
            'malformed_rows' => (int) $parsed['malformed_rows'],
            'category_counts' => $categoryCounts,
            'detail_counts' => $detailCounts,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function recordMap(array $records): array
    {
        $map = [];

        foreach ($records as $record) {
            $id = trim((string) ($record['wialon_unit_id'] ?? ''));
            $name = $this->normalizeName((string) ($record['unit_name'] ?? ''));
            $key = $id !== '' ? 'id:'.$id : 'name:'.$name;
            $record['_match_key'] = $key;

            if ($id !== '') {
                $map['id:'.$id] = $record;
            }

            if ($name !== '') {
                $map['name:'.$name] = $record;
            }
        }

        return $map;
    }

    private function findRecord(array $map, Equipment $equipment): ?array
    {
        $id = trim((string) $equipment->wialon_unit_id);
        $name = $this->normalizeName($equipment->name);

        return ($id !== '' ? ($map['id:'.$id] ?? null) : null)
            ?? ($name !== '' ? ($map['name:'.$name] ?? null) : null);
    }

    /** @param array{category: string, detail_status: string} $classification */
    private function factRow(
        ProjectWialonGroup $group,
        Equipment $equipment,
        CarbonImmutable $date,
        ?array $record,
        array $classification,
        array $report,
        CarbonImmutable $now
    ): array {
        $hours = $record['engine_hours_decimal'] ?? null;

        return [
            'fact_date' => $date->toDateString(),
            'equipment_id' => $equipment->id,
            'wialon_unit_id' => (string) ($equipment->wialon_unit_id ?: ($record['wialon_unit_id'] ?? '')),
            'unit_name_snapshot' => $equipment->name,
            'project_id' => $equipment->project_id,
            'project_name_snapshot' => $equipment->project?->name,
            'ownership_type' => $equipment->ownership_type,
            'equipment_type_id' => $equipment->equipment_type_id,
            'equipment_type_canonical' => FleetVehicleType::label($equipment->type?->name),
            'wialon_equipment_type' => $record['wialon_equipment_type'] ?? null,
            'wialon_vendor' => $record['vendor'] ?? null,
            'model_name' => $record['model_name'] ?? null,
            'manufacturer_name' => $record['manufacturer_name'] ?? null,
            'year' => $record['year'] ?? null,
            'report_resource_id' => (string) ($report['resource_id'] ?? ''),
            'report_template_id' => (string) ($report['template_id'] ?? ''),
            'report_template_name' => (string) ($report['template_name'] ?? config('daytime_efficiency.report_template_name')),
            'source_group_id' => (string) $group->wialon_group_id,
            'report_row_found' => $record !== null,
            'raw_engine_hours' => $record['raw_engine_hours'] ?? null,
            'engine_hours_decimal' => $hours,
            'engine_hours_seconds' => $record['engine_hours_seconds'] ?? null,
            'raw_idling' => $record['raw_idling'] ?? null,
            'idling_hours' => $record['idling_hours'] ?? null,
            'raw_mileage' => $record['raw_mileage'] ?? null,
            'mileage_adjusted' => $record['mileage_adjusted'] ?? null,
            'beginning_at' => $record['beginning_at'] ?? null,
            'end_at' => $record['end_at'] ?? null,
            'category' => $classification['category'],
            'detail_status' => $classification['detail_status'],
            'parse_status' => $record === null ? 'missing' : (($record['parse_succeeded'] ?? false) ? 'parsed' : 'failed'),
            'source_hash' => hash('sha256', json_encode([
                $equipment->id,
                $date->toDateString(),
                $record['raw_row'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'calculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function normalizeName(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', mb_strtolower(trim($value))) ?? '';
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'equipment_count' => 0,
            'report_rows' => 0,
            'saved_rows' => 0,
            'unmatched_rows' => 0,
            'duplicate_rows' => 0,
            'malformed_rows' => 0,
            'category_counts' => [],
            'detail_counts' => [],
        ];
    }

    private function timezone(): string
    {
        return (string) config('daytime_efficiency.timezone', 'Asia/Baku');
    }
}
