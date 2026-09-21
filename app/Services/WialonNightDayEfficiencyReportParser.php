<?php

namespace App\Services;

use Carbon\CarbonInterface;

class WialonNightDayEfficiencyReportParser extends WialonDaytimeEfficiencyReportParser
{
    /** @return array{records: array<int, array<string, mixed>>, rows_received: int} */
    public function parse(array $report): array
    {
        $tables = $report['tables'] ?? null;

        if (is_array($tables) && ! collect($tables)->contains(
            fn (array $item): bool => in_array('duration', $item['table']['header_type'] ?? [], true)
        )) {
            return ['records' => [], 'rows_received' => 0];
        }

        $parsed = parent::parse($report);
        $records = [];
        $recordsByUnit = [];

        foreach ($parsed['records'] as $record) {
            $unitId = $record['wialon_unit_id'] ?? null;

            if ($unitId === null || $unitId === '') {
                $records[] = $record;

                continue;
            }

            $recordsByUnit[(string) $unitId][] = $record;
        }

        foreach ($recordsByUnit as $unitRecords) {
            $records[] = $this->aggregateUnitRecords($unitRecords);
        }

        return ['records' => $records, 'rows_received' => $parsed['rows_received']];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function aggregateUnitRecords(array $records): array
    {
        $record = $records[0];
        $engineSeconds = array_sum(array_map(
            fn (array $item): int => max(0, (int) ($item['engine_seconds'] ?? 0)),
            $records,
        ));
        $mileages = array_values(array_filter(
            array_map(fn (array $item): mixed => $item['mileage_km'] ?? null, $records),
            fn (mixed $value): bool => $value !== null,
        ));

        $record['engine_seconds'] = $engineSeconds;
        $record['engine_hours_decimal'] = round($engineSeconds / 3600, 2);
        $record['engine_hours_raw'] = number_format($engineSeconds / 3600, 2, '.', '');
        $record['started_at'] = $this->boundary($records, 'started_at', true);
        $record['ended_at'] = $this->boundary($records, 'ended_at', false);
        $record['mileage_km'] = $mileages === [] ? null : round(array_sum($mileages), 2);
        $record['mileage_raw'] = $mileages === []
            ? null
            : number_format((float) $record['mileage_km'], 2, '.', '').' km';
        $record['raw_row_json'] = array_column($records, 'raw_row_json');

        return $record;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function boundary(array $records, string $key, bool $earliest): mixed
    {
        $values = array_values(array_filter(
            array_map(fn (array $record): mixed => $record[$key] ?? null, $records),
            fn (mixed $value): bool => $value instanceof CarbonInterface,
        ));

        if ($values === []) {
            return null;
        }

        usort($values, fn (CarbonInterface $left, CarbonInterface $right): int => $left->getTimestamp() <=> $right->getTimestamp());

        return $earliest ? $values[0] : $values[array_key_last($values)];
    }
}
