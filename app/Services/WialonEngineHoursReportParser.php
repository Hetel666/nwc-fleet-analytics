<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class WialonEngineHoursReportParser
{
    /**
     * @return array{records: array<int, array<string, mixed>>, tables: array<int, array<string, mixed>>, null_rows: int, invalid_rows: int, raw: array<string, mixed>}
     */
    public function parse(array $report): array
    {
        $records = [];
        $tables = [];
        $nullRows = 0;
        $invalidRows = 0;
        $rawTable = null;
        $rawRow = null;

        foreach (($report['tables'] ?? []) as $tableIndex => $reportTable) {
            if (! is_array($reportTable)) {
                continue;
            }

            $table = is_array($reportTable['table'] ?? null) ? $reportTable['table'] : [];
            $headers = $this->headers($table);
            $engineIndex = $this->engineHoursColumnIndex($headers, $table);
            $tableName = (string) (($table['name'] ?? null) ?: ($table['label'] ?? ''));
            $rawTable ??= $this->safeTable($table);
            $parsed = 0;

            if ($engineIndex === null) {
                $tables[] = [
                    'index' => (int) ($reportTable['index'] ?? $tableIndex),
                    'name' => $tableName,
                    'rows' => count($reportTable['rows'] ?? []),
                    'parsed_records' => 0,
                    'engine_hours_column_index' => null,
                    'engine_hours_column_label' => null,
                ];
                continue;
            }

            foreach ($this->flattenRows(array_values(array_filter($reportTable['rows'] ?? [], 'is_array'))) as $row) {
                if ($this->isParentOrSummaryRow($row)) {
                    continue;
                }

                $record = $this->parseRow($row, $headers, $engineIndex, $report, $tableName);

                if ($record === null) {
                    continue;
                }

                $parsed++;
                $rawRow ??= $record['_raw'];
                unset($record['_raw']);

                if ($record['engine_hours'] === null) {
                    $record['parse_status'] === 'negative_engine_hours' ? $invalidRows++ : $nullRows++;
                }

                $records[] = $record;
            }

            $tables[] = [
                'index' => (int) ($reportTable['index'] ?? $tableIndex),
                'name' => $tableName,
                'rows' => count($reportTable['rows'] ?? []),
                'parsed_records' => $parsed,
                'engine_hours_column_index' => $engineIndex,
                'engine_hours_column_label' => $headers[$engineIndex] ?? null,
                'sample_rows' => collect(array_values(array_filter($reportTable['rows'] ?? [], 'is_array')))
                    ->take(3)
                    ->map(fn (array $row): array => $this->safeRow($row))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'records' => $this->deduplicate($records),
            'tables' => $tables,
            'null_rows' => $nullRows,
            'invalid_rows' => $invalidRows,
            'raw' => [
                'table' => $rawTable,
                'row' => $rawRow,
            ],
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function parseRow(array $row, array $headers, int $engineIndex, array $report, string $tableName): ?array
    {
        $cells = $row['c'] ?? $row['cells'] ?? [];
        $unitName = $this->unitName($row, $headers, $cells);
        $unitId = $this->wialonUnitId($row);

        if ($unitName === '' && $unitId === null) {
            return null;
        }

        if (($unitId === null || $unitId === '0') && $this->parseDate($unitName) !== null) {
            return null;
        }

        $date = $this->statDate($row, $headers, $cells, $report);

        if ($date === null) {
            return null;
        }

        $rawValue = $cells[$engineIndex] ?? null;
        $hours = $this->parseDuration($rawValue);
        $status = 'ok';

        if ($hours === null) {
            $status = 'engine_hours_null';
        } elseif ($hours < 0) {
            $hours = null;
            $status = 'negative_engine_hours';
        }

        return [
            'wialon_unit_id' => $unitId,
            'unit_name' => $unitName,
            'statistic_date' => $date->toDateString(),
            'engine_hours' => $hours === null ? null : round($hours, 2),
            'parse_status' => $status,
            'source_table' => $tableName,
            'engine_hours_column_index' => $engineIndex,
            'engine_hours_column_label' => $headers[$engineIndex] ?? 'Engine hours',
            'raw_value' => $this->safeValue($rawValue),
            '_raw' => $this->safeRow($row),
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function engineHoursColumnIndex(array $headers, array $table): ?int
    {
        foreach ($headers as $index => $header) {
            if ($this->normalizeHeader($header) === 'engine hours') {
                return (int) $index;
            }
        }

        foreach (($table['header_type'] ?? []) as $index => $type) {
            if (in_array($this->normalizeHeader((string) $type), ['duration', 'engine hours'], true)) {
                $label = $this->normalizeHeader($headers[$index] ?? '');

                if ($label === 'engine hours' || str_contains($label, 'engine')) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flattenRows(array $rows): array
    {
        $flat = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $flat[] = $row;
            $children = $this->rowChildren($row);

            if ($children !== []) {
                $flat = array_merge($flat, $this->flattenRows($children));
            }
        }

        return $flat;
    }

    private function isParentOrSummaryRow(array $row): bool
    {
        $cells = $row['c'] ?? $row['cells'] ?? [];
        $first = $this->cellText($cells[0] ?? null);
        $unitId = $this->wialonUnitId($row);

        return in_array($this->normalizeHeader($first), ['total', 'summary', 'cemi', 'cəmi'], true)
            || ($this->rowChildren($row) !== [] && ($unitId === null || $unitId === '0'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowChildren(array $row): array
    {
        foreach (['r', 'rows', 'children'] as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return array_values(array_filter($row[$key], 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, mixed> $cells
     */
    private function unitName(array $row, array $headers, array $cells): string
    {
        foreach (['unit', 'unit_name', 'name', 'nm'] as $key) {
            $value = $this->cellText($row[$key] ?? null);

            if ($value !== '') {
                return $value;
            }
        }

        $index = $this->columnIndex($headers, ['unit', 'object', 'texnika', 'grouping']);

        return $this->cellText($cells[$index ?? 0] ?? null);
    }

    private function wialonUnitId(array $row): ?string
    {
        foreach (['unitId', 'unit_id', 'itemId', 'uid', 'id'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        foreach (($row['c'] ?? $row['cells'] ?? []) as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            foreach (['i', 'id', 'unitId', 'itemId', 'uid'] as $key) {
                if (isset($cell[$key]) && is_scalar($cell[$key]) && trim((string) $cell[$key]) !== '') {
                    return trim((string) $cell[$key]);
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, mixed> $cells
     */
    private function statDate(array $row, array $headers, array $cells, array $report): ?CarbonInterface
    {
        $index = $this->columnIndex($headers, ['date', 'tarix', 'day']);
        $date = $index === null ? null : $this->parseDate($cells[$index] ?? null);

        if ($date !== null) {
            return $date;
        }

        $from = $report['from'] ?? null;
        $to = $report['to'] ?? null;

        if ($from instanceof CarbonInterface && $to instanceof CarbonInterface && $from->toDateString() === $to->toDateString()) {
            return $from->timezone($this->timezone())->startOfDay();
        }

        foreach ($cells as $cell) {
            $date = $this->parseDate($cell);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    public function parseDuration(mixed $value): ?float
    {
        if (is_array($value)) {
            $text = $this->cellText($value['t'] ?? null);

            if ($text !== '') {
                $parsed = $this->parseDuration($text);

                if ($parsed !== null) {
                    return $parsed;
                }
            }

            $numeric = $value['v'] ?? null;

            if (is_numeric($numeric)) {
                $number = (float) $numeric;

                return abs($number) > 24 ? $number / 3600 : $number;
            }

            return null;
        }

        $text = $this->cellText($value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^-?\d+(?:[,.]\d+)?$/', $text)) {
            $number = (float) str_replace(',', '.', $text);

            return abs($number) > 24 ? $number / 3600 : $number;
        }

        if (preg_match('/^(-?)(?:(\d+)\s+day[s]?\s+)?(\d+):(\d{2})(?::(\d{2}))?$/i', $text, $matches)) {
            $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
            $days = (int) ($matches[2] ?? 0);
            $hours = (int) $matches[3];
            $minutes = (int) $matches[4];
            $seconds = (int) ($matches[5] ?? 0);

            return $sign * (($days * 24 + $hours) + ($minutes / 60) + ($seconds / 3600));
        }

        return null;
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (is_array($value)) {
            if (isset($value['v']) && is_numeric($value['v']) && (int) $value['v'] > 1000000000) {
                return CarbonImmutable::createFromTimestamp((int) $value['v'], $this->timezone())->startOfDay();
            }

            $value = $value['t'] ?? null;
        }

        $text = $this->cellText($value);

        if ($text === '' || preg_match('/^\d{4}$/', $text) || preg_match('/^\d{1,2}:\d{2}/', $text)) {
            return null;
        }

        if (! preg_match('/\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4}/', $text)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text, $this->timezone())->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $headers
     */
    private function columnIndex(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            foreach ($needles as $needle) {
                if ($normalized === $this->normalizeHeader($needle)) {
                    return (int) $index;
                }
            }
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

    private function cellText(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        $text = trim((string) $value);

        return in_array($text, ['', '-', '-----'], true) ? '' : preg_replace('/\s+/u', ' ', $text);
    }

    private function normalizeHeader(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    private function deduplicate(array $records): array
    {
        $deduplicated = [];

        foreach ($records as $record) {
            $key = ($record['wialon_unit_id'] ?: mb_strtolower($record['unit_name'])).'|'.$record['statistic_date'];

            if (! isset($deduplicated[$key])) {
                $deduplicated[$key] = $record;
            }
        }

        return array_values($deduplicated);
    }

    private function safeValue(mixed $value): array
    {
        return is_array($value) ? array_intersect_key($value, array_flip(['t', 'v', 'u', 'i'])) : ['t' => $value];
    }

    private function safeRow(array $row): array
    {
        return [
            'c' => array_slice($row['c'] ?? $row['cells'] ?? [], 0, 20),
            'keys' => array_keys($row),
            'has_children' => $this->rowChildren($row) !== [],
        ];
    }

    private function safeTable(array $table): array
    {
        return [
            'name' => $table['name'] ?? null,
            'label' => $table['label'] ?? null,
            'header' => $table['header'] ?? [],
            'header_type' => $table['header_type'] ?? [],
            'rows' => $table['rows'] ?? null,
            'level' => $table['level'] ?? null,
        ];
    }

    private function timezone(): string
    {
        return (string) config('fleet_efficiency.timezone', config('app.timezone', 'Asia/Baku'));
    }
}
