<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class WialonGeozonReportParser
{
    public function __construct(private GeofenceNameNormalizer $normalizer)
    {
    }

    /**
     * @return array{records: array<int, array<string, mixed>>, parent_rows: int, nested_rows: int, columns: array<int, string>, raw: array<string, mixed>}
     */
    public function parse(array $report): array
    {
        $table = $report['table'] ?? [];
        $headers = $this->headers($table);
        $records = [];
        $parentRows = 0;
        $nestedRows = 0;
        $rawParent = null;
        $rawChild = null;

        foreach (($report['rows'] ?? []) as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $parent = $this->parseParentGeofenceRow($row, $headers, (int) $rowIndex);

            if ($parent === null) {
                continue;
            }

            $parentRows++;
            $rawParent ??= $this->safeRow($row);
            $children = $this->nestedRows($row);
            $nestedRows += count($children);

            foreach ($this->parseNestedUnitRows($children, $parent, $headers) as $record) {
                $rawChild ??= $record['_raw'] ?? null;
                unset($record['_raw']);
                $records[] = $record;
            }
        }

        return [
            'records' => $records,
            'parent_rows' => $parentRows,
            'nested_rows' => $nestedRows,
            'columns' => $headers,
            'raw' => [
                'table' => $this->safeTable($table),
                'parent_row' => $rawParent,
                'child_row' => $rawChild,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, mixed>|null
     */
    public function parseParentGeofenceRow(array $row, array $headers, int $rowIndex = 0): ?array
    {
        $cells = $this->rowCells($row);
        $groupingIndex = $this->columnIndex($headers, ['grouping', 'qrup', 'группировка']);
        $geofenceIndex = $this->columnIndex($headers, ['geofence', 'geozone', 'zone', 'геозона']);
        $grouping = $this->normalizeCellValue($cells[$groupingIndex ?? 0] ?? null);
        $geofence = $this->normalizeCellValue($geofenceIndex !== null ? ($cells[$geofenceIndex] ?? null) : null);
        $geofenceName = $grouping !== '' ? $grouping : $geofence;

        if ($geofenceName === '' || $this->nestedRows($row) === []) {
            return null;
        }

        return [
            'row_index' => $rowIndex,
            'geofence_name' => $geofenceName,
            'geofence_normalized_name' => $this->normalizer->normalize($geofenceName),
            'wialon_geofence_id' => $this->extractWialonId($row, ['geofenceId', 'geozoneId', 'zoneId', 'gid', 'zid']),
            'nested_count' => $this->nestedCount($row),
            'cells' => $cells,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $parent
     * @param  array<int, string>  $headers
     * @return array<int, array<string, mixed>>
     */
    public function parseNestedUnitRows(array $rows, array $parent, array $headers): array
    {
        $records = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $children = $this->nestedRows($row);

            if ($children !== []) {
                $records = array_merge($records, $this->parseNestedUnitRows($children, $parent, $headers));
                continue;
            }

            $record = $this->parseUnitRow($row, $parent, $headers);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function normalizeCellValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        $text = trim((string) $value);

        return in_array($text, ['', '-', '-----', 'Total'], true) ? '' : $text;
    }

    public function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if (is_array($value)) {
            $text = $this->normalizeCellValue($value['t'] ?? null);

            if ($text !== '') {
                try {
                    return CarbonImmutable::parse($text, config('app.timezone'));
                } catch (Throwable) {
                    // Fall back to the numeric value below.
                }
            }

            $numeric = $value['v'] ?? null;

            if (is_numeric($numeric) && (int) $numeric > 1000000000) {
                return CarbonImmutable::createFromTimestamp((int) $numeric, config('app.timezone'));
            }

            $value = $value['t'] ?? $numeric;
        }

        if (is_numeric($value) && (int) $value > 1000000000) {
            return CarbonImmutable::createFromTimestamp((int) $value, config('app.timezone'));
        }

        $text = $this->normalizeCellValue($value);

        if ($text === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($text, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public function parseDuration(mixed $value): ?int
    {
        if (is_array($value)) {
            $numeric = $value['v'] ?? null;

            if (is_numeric($numeric)) {
                return (int) round((float) $numeric);
            }

            $value = $value['t'] ?? null;
        }

        $text = $this->normalizeCellValue($value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^(?:(\d+)\s+day[s]?\s+)?(\d+):(\d{2})(?::(\d{2}))?$/i', $text, $matches)) {
            $days = (int) ($matches[1] ?? 0);
            $hours = (int) $matches[2];
            $minutes = (int) $matches[3];
            $seconds = (int) ($matches[4] ?? 0);

            return (($days * 24 + $hours) * 3600) + ($minutes * 60) + $seconds;
        }

        if (preg_match('/^\d+(?:[,.]\d+)?$/', $text)) {
            return (int) round(((float) str_replace(',', '.', $text)) * 3600);
        }

        if (preg_match('/(?:(\d+)\s*saat)?\s*(?:(\d+)\s*d[eə]qiq[eə])?/iu', $text, $matches) && trim($matches[0] ?? '') !== '') {
            return ((int) ($matches[1] ?? 0) * 3600) + ((int) ($matches[2] ?? 0) * 60);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<int, string>  $headers
     * @return array<string, mixed>|null
     */
    private function parseUnitRow(array $row, array $parent, array $headers): ?array
    {
        $cells = $this->rowCells($row);
        $groupingIndex = $this->columnIndex($headers, ['grouping', 'texnika', 'equipment', 'unit', 'объект']);
        $projectIndex = $this->columnIndex($headers, ['project', 'layih', 'проект']);
        $geofenceIndex = $this->columnIndex($headers, ['geofence', 'geozone', 'zone', 'геозона']);
        $entryIndex = $this->columnIndex($headers, ['entry', 'begin', 'start', 'giris', 'giriş', 'время входа']);
        $exitIndex = $this->columnIndex($headers, ['exit', 'end', 'finish', 'cixis', 'çıxış', 'время выхода']);
        $durationIndex = $this->columnIndex($headers, ['duration', 'stay', 'qalma', 'длительность']);
        $unitName = $this->normalizeCellValue($cells[$groupingIndex ?? 0] ?? null);

        if ($unitName === '') {
            return null;
        }

        $entryAt = $entryIndex !== null ? $this->parseTimestamp($cells[$entryIndex] ?? null) : null;
        $exitAt = $exitIndex !== null ? $this->parseTimestamp($cells[$exitIndex] ?? null) : null;
        $durationSeconds = $durationIndex !== null ? $this->parseDuration($cells[$durationIndex] ?? null) : null;

        if ($durationSeconds === null && $entryAt !== null && $exitAt !== null && ! $exitAt->lessThan($entryAt)) {
            $durationSeconds = (int) $entryAt->diffInSeconds($exitAt, true);
        }

        return [
            'wialon_unit_id' => $this->extractWialonId($row, ['unitId', 'unit_id', 'itemId', 'uid']),
            'unit_name' => $unitName,
            'reported_project' => $projectIndex !== null ? $this->normalizeCellValue($cells[$projectIndex] ?? null) : '',
            'visited_geofence_id' => $parent['wialon_geofence_id'] ?? null,
            'visited_geofence_name' => $parent['geofence_name'],
            'visited_geofence_normalized_name' => $parent['geofence_normalized_name'],
            'reported_geofence' => $geofenceIndex !== null ? $this->normalizeCellValue($cells[$geofenceIndex] ?? null) : '',
            'entry_at' => $entryAt,
            'exit_at' => $exitAt,
            'duration_seconds' => $durationSeconds,
            'invalid_reason' => $this->invalidReason($entryAt, $exitAt, $durationSeconds),
            '_raw' => $this->safeRow($row),
        ];
    }

    private function invalidReason(?CarbonInterface $entryAt, ?CarbonInterface $exitAt, ?int $durationSeconds): ?string
    {
        if ($entryAt === null) {
            return 'invalid_entry_time';
        }

        if ($exitAt === null) {
            return 'invalid_exit_time';
        }

        if ($exitAt->lessThan($entryAt)) {
            return 'invalid_exit_time';
        }

        if ($durationSeconds === null || $durationSeconds < 0) {
            return 'invalid_duration';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function headers(array $table): array
    {
        return array_map(fn (mixed $header): string => trim(preg_replace('/\s+/u', ' ', (string) $header) ?? (string) $header), $table['header'] ?? []);
    }

    /**
     * @return array<int, mixed>
     */
    private function rowCells(array $row): array
    {
        return $row['c'] ?? $row['cells'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nestedRows(array $row): array
    {
        foreach (['r', 'rows', 'children'] as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return array_values(array_filter($row[$key], 'is_array'));
            }
        }

        return [];
    }

    private function nestedCount(array $row): int
    {
        foreach (['n', 'count', 'cnt', 'rows_count'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        return count($this->nestedRows($row));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function columnIndex(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizer->normalize($header);

            foreach ($needles as $needle) {
                if (str_contains($normalized, $this->normalizer->normalize($needle))) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function extractWialonId(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            foreach ([$key, mb_strtolower($key)] as $candidate) {
                if (isset($row[$candidate]) && is_scalar($row[$candidate]) && (string) $row[$candidate] !== '') {
                    return (string) $row[$candidate];
                }
            }
        }

        foreach ($this->rowCells($row) as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            foreach (['i', 'id', 'unitId', 'itemId', 'uid', 'geofenceId', 'zoneId'] as $key) {
                if (isset($cell[$key]) && is_scalar($cell[$key]) && (string) $cell[$key] !== '') {
                    return (string) $cell[$key];
                }
            }
        }

        return null;
    }

    private function safeTable(array $table): array
    {
        return [
            'name' => $table['name'] ?? null,
            'label' => $table['label'] ?? null,
            'header' => $table['header'] ?? [],
            'rows' => $table['rows'] ?? null,
            'level' => $table['level'] ?? null,
        ];
    }

    private function safeRow(array $row): array
    {
        return [
            'c' => $row['c'] ?? $row['cells'] ?? [],
            'n' => $row['n'] ?? null,
            'count' => $row['count'] ?? null,
            'level' => $row['level'] ?? null,
            'has_children' => $this->nestedRows($row) !== [],
            'keys' => array_keys($row),
        ];
    }
}
