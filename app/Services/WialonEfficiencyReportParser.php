<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class WialonEfficiencyReportParser
{
    /** @return array{records: array<int, array<string, mixed>>, rows_received: int} */
    public function parse(array $report): array
    {
        $records = [];
        $rowsReceived = 0;
        $matchedTable = false;
        $reportTables = $report['tables'] ?? null;

        if ($reportTables === []
            && is_array($report['result']['reportResult'] ?? null)
            && ($report['result']['reportResult']['tables'] ?? null) === []) {
            return ['records' => [], 'rows_received' => 0];
        }

        if (! is_array($reportTables)) {
            throw new RuntimeException('Wialon efficiency report tables are missing.');
        }

        foreach ($reportTables as $tablePosition => $reportTable) {
            $table = $reportTable['table'] ?? [];
            $headers = array_map(fn ($value): string => trim((string) $value), $table['header'] ?? []);
            $types = $table['header_type'] ?? [];
            $indexes = $this->columnIndexes($headers, $types);

            if ($indexes['engine_hours'] === null || $indexes['grouping'] === null) {
                continue;
            }

            if ($indexes['begin'] === null || $indexes['end'] === null || $indexes['mileage'] === null) {
                throw new RuntimeException('Wialon efficiency report is missing one or more required columns.');
            }

            $matchedTable = true;
            $tableName = (string) (($table['name'] ?? null) ?: ($table['label'] ?? 'Qrup report Engine hours'));
            $tableIndex = (int) ($reportTable['index'] ?? $tablePosition);

            foreach (($reportTable['rows'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $rowsReceived++;
                $cells = $row['c'] ?? $row['cells'] ?? [];
                $unitId = $this->unitId($row, $cells);
                $engineRaw = $this->text($cells[$indexes['engine_hours']] ?? null);
                $hours = $this->decimal($cells[$indexes['engine_hours']] ?? null);
                $mileageRaw = $indexes['mileage'] === null ? null : $this->text($cells[$indexes['mileage']] ?? null);

                $records[] = [
                    'wialon_unit_id' => $unitId,
                    'unit_name' => $this->text($cells[$indexes['grouping']] ?? null),
                    'engine_hours_decimal' => round(max(0, $hours ?? 0), 2),
                    'engine_seconds' => (int) round(max(0, $hours ?? 0) * 3600),
                    'engine_hours_raw' => $engineRaw,
                    'started_at' => $indexes['begin'] === null ? null : $this->dateTime($cells[$indexes['begin']] ?? null),
                    'ended_at' => $indexes['end'] === null ? null : $this->dateTime($cells[$indexes['end']] ?? null),
                    'mileage_km' => $this->decimal($cells[$indexes['mileage']] ?? null),
                    'mileage_raw' => $mileageRaw,
                    'source_table' => $tableName,
                    'source_table_index' => $tableIndex,
                    'engine_hours_column_index' => $indexes['engine_hours'],
                    'engine_hours_column_label' => $headers[$indexes['engine_hours']] ?? 'Engine hours',
                    'raw_row_json' => $row,
                ];
            }
        }

        if (! $matchedTable) {
            throw new RuntimeException('Wialon efficiency report table could not be matched by metadata.');
        }

        return ['records' => $records, 'rows_received' => $rowsReceived];
    }

    public function decimal(mixed $value): ?float
    {
        if (is_array($value)) {
            $text = $this->text($value['t'] ?? null);

            if ($text !== '') {
                return $this->decimal($text);
            }

            $value = $value['v'] ?? null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);

        if ($text === '' || $text === '-') {
            return null;
        }

        $normalized = preg_replace('/[^0-9,.-]+/u', '', str_replace([' ', "\u{00A0}"], '', $text));

        if ($normalized === null || $normalized === '' || $normalized === '-') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace(['.', ','], ['', '.'], $normalized)
                : str_replace(',', '', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /** @return array<string, int|null> */
    private function columnIndexes(array $headers, array $types): array
    {
        return [
            'grouping' => $this->index($headers, ['grouping', 'группировка'], $types, ['']),
            'engine_hours' => $this->index($headers, ['engine hours'], $types, ['duration']),
            'begin' => $this->index($headers, ['begin', 'start', 'начало'], $types, ['time_begin']),
            'end' => $this->index($headers, ['end', 'конец'], $types, ['time_end']),
            'mileage' => $this->index($headers, ['mileage', 'пробег'], $types, ['mileage']),
        ];
    }

    private function index(array $headers, array $names, array $types, array $wantedTypes): ?int
    {
        foreach ($types as $index => $type) {
            if (in_array($this->normalize((string) $type), $wantedTypes, true)) {
                return (int) $index;
            }
        }

        foreach ($headers as $index => $header) {
            if (in_array($this->normalize($header), $names, true)) {
                return (int) $index;
            }
        }

        return null;
    }

    private function unitId(array $row, array $cells): ?string
    {
        foreach (['uid', 'unitId', 'unit_id', 'itemId'] as $key) {
            if (filled($row[$key] ?? null)) {
                return (string) $row[$key];
            }
        }

        foreach ($cells as $cell) {
            if (is_array($cell) && filled($cell['u'] ?? null)) {
                return (string) $cell['u'];
            }
        }

        return null;
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        if (is_array($value) && is_numeric($value['v'] ?? null)) {
            return CarbonImmutable::createFromTimestamp((int) $value['v'], 'UTC')->timezone($this->timezone());
        }

        $text = $this->text($value);

        try {
            return $text === '' ? null : CarbonImmutable::parse($text, $this->timezone());
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function timezone(): string
    {
        return (string) config('historical_recalculation.timezone', 'Asia/Baku');
    }
}
