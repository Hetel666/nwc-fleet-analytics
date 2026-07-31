<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class WialonDaytimeEfficiencyReportParser
{
    /**
     * @return array{records: array<int, array<string, mixed>>, duplicates: array<int, string>, malformed_rows: int}
     */
    public function parse(array $report): array
    {
        $records = [];
        $seen = [];
        $duplicates = [];
        $malformedRows = 0;
        $reportDate = $this->reportDate($report);

        foreach (($report['tables'] ?? []) as $reportTable) {
            if (! is_array($reportTable)) {
                continue;
            }

            $table = is_array($reportTable['table'] ?? null) ? $reportTable['table'] : [];
            $headers = array_values(array_map(fn (mixed $value): string => trim((string) $value), $table['header'] ?? []));
            $headerTypes = array_values(array_map(fn (mixed $value): string => trim((string) $value), $table['header_type'] ?? []));
            $indexes = $this->columnIndexes($headers, $headerTypes);

            foreach ($this->leafRows(array_values(array_filter($reportTable['rows'] ?? [], 'is_array'))) as $row) {
                $record = $this->parseRow($row, $indexes, $reportDate);

                if ($record === null) {
                    $malformedRows++;

                    continue;
                }

                $key = ($record['wialon_unit_id'] ?: mb_strtolower($record['unit_name'])).'|'.$record['fact_date'];

                if (isset($seen[$key])) {
                    $duplicates[] = $key;

                    continue;
                }

                $seen[$key] = true;
                $records[] = $record;
            }
        }

        return [
            'records' => $records,
            'duplicates' => array_values(array_unique($duplicates)),
            'malformed_rows' => $malformedRows,
        ];
    }

    /**
     * @param  array<string, int|null>  $indexes
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row, array $indexes, CarbonImmutable $reportDate): ?array
    {
        $cells = array_values(is_array($row['c'] ?? null) ? $row['c'] : []);
        $cell = static fn (string $key, ?int $fallback = null): mixed
            => isset($indexes[$key])
                ? ($cells[$indexes[$key]] ?? null)
                : ($fallback === null ? null : ($cells[$fallback] ?? null));

        $unitName = trim($this->displayValue($cell('unit', 0)));
        $unitId = $this->unitId($row);

        if ($unitName === '' && $unitId === null) {
            return null;
        }

        $rawHoursCell = $cell('engine_hours', 3);
        $rawHours = $this->displayValue($rawHoursCell);
        $hours = $this->parseHours($rawHoursCell);
        $rawMileageCell = $cell('mileage', 8);
        $rawIdlingCell = $cell('idling', 7);
        $beginning = $this->parseTimestamp(
            $cell('beginning', 9),
            $row['t1'] ?? null,
            $reportDate
        );
        $end = $this->parseTimestamp(
            $cell('end', 10),
            $row['t2'] ?? null,
            $reportDate
        );

        return [
            'fact_date' => $reportDate->toDateString(),
            'wialon_unit_id' => $unitId,
            'unit_name' => $unitName,
            'model_name' => $this->displayValue($cell('model', 1)),
            'manufacturer_name' => $this->displayValue($cell('manufacturer', 2)),
            'raw_engine_hours' => $rawHours,
            'engine_hours_decimal' => $hours,
            'engine_hours_seconds' => $hours === null ? null : (int) round($hours * 3600),
            'wialon_equipment_type' => $this->displayValue($cell('equipment_type', 4)),
            'vendor' => $this->displayValue($cell('vendor', 5)),
            'year' => $this->displayValue($cell('year', 6)),
            'raw_idling' => $this->displayValue($rawIdlingCell),
            'idling_hours' => $this->parseHours($rawIdlingCell),
            'raw_mileage' => $this->displayValue($rawMileageCell),
            'mileage_adjusted' => $this->parseMileage($rawMileageCell),
            'beginning_at' => $beginning,
            'end_at' => $end,
            'raw_value_is_empty' => trim($rawHours) === '',
            'parse_succeeded' => $hours !== null,
            'raw_row' => $this->safeJson($row),
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $headerTypes
     * @return array<string, int|null>
     */
    private function columnIndexes(array $headers, array $headerTypes): array
    {
        $normalized = array_map($this->normalize(...), $headers);
        $types = array_map($this->normalize(...), $headerTypes);
        $custom = [];

        foreach ($types as $index => $type) {
            if ($type === 'user column') {
                $custom[] = $index;
            }
        }

        // Wialon report columns are configurable. Once a column is removed,
        // positional fallbacks would read a neighbouring value as another field.
        if ($headers !== []) {
            $find = fn (array $values, array $candidates): ?int => $this->findIndex($values, $candidates);

            return [
                'unit' => $find($normalized, ['grouping']) ?? 0,
                'model' => $custom[0] ?? null,
                'manufacturer' => $custom[1] ?? null,
                'engine_hours' => $find($normalized, ['engine hours'])
                    ?? $find($types, ['duration']),
                'equipment_type' => $find($normalized, ['equipment type']),
                'vendor' => $find($normalized, ['vendor', 'ownership']),
                'year' => $find($normalized, ['year']),
                'idling' => $find($normalized, ['idling'])
                    ?? $find($types, ['duration stay']),
                'mileage' => $find($normalized, ['mileage adjusted', 'mileage'])
                    ?? $find($types, ['correct mileage']),
                'beginning' => $find($normalized, ['beginning'])
                    ?? $find($types, ['time begin']),
                'end' => $find($normalized, ['end'])
                    ?? $find($types, ['time end']),
            ];
        }

        return [
            'unit' => $this->findIndex($normalized, ['grouping', 'группировка', 'qruplaşdırma']) ?? 0,
            'model' => $custom[0] ?? 1,
            'manufacturer' => $custom[1] ?? 2,
            'engine_hours' => $this->findIndex($normalized, ['engine hours', 'моточасы'])
                ?? $this->findIndex($types, ['duration'])
                ?? 3,
            'equipment_type' => $this->findIndex($normalized, ['equipment type', 'тип техники']) ?? 4,
            'vendor' => $this->findIndex($normalized, ['vendor', 'ownership', 'владелец']) ?? 5,
            'year' => $this->findIndex($normalized, ['year', 'год']) ?? 6,
            'idling' => $this->findIndex($normalized, ['idling', 'холостой ход']) ?? 7,
            'mileage' => $this->findIndex($normalized, ['mileage adjusted', 'mileage', 'пробег']) ?? 8,
            'beginning' => $this->findIndex($normalized, ['beginning', 'начало']) ?? 9,
            'end' => $this->findIndex($normalized, ['end', 'конец']) ?? 10,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function leafRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $children = [];

            foreach (['r', 'rows', 'children'] as $key) {
                if (isset($row[$key]) && is_array($row[$key])) {
                    $children = array_values(array_filter($row[$key], 'is_array'));
                    break;
                }
            }

            if ($children !== []) {
                array_push($result, ...$this->leafRows($children));
            } else {
                $result[] = $row;
            }
        }

        return $result;
    }

    private function parseHours(mixed $value): ?float
    {
        if (is_array($value)) {
            $text = trim((string) ($value['t'] ?? ''));

            if ($text !== '') {
                return $this->parseHours($text);
            }

            if (isset($value['v']) && is_numeric($value['v'])) {
                return round(max(0, (float) $value['v'] / 3600), 4);
            }

            return null;
        }

        $text = trim(str_replace(',', '.', (string) $value));

        if ($text === '') {
            return null;
        }

        if (is_numeric($text)) {
            return round(max(0, (float) $text), 4);
        }

        if (preg_match('/^(?:(\d+)\s+day[s]?\s+)?(\d+):(\d{2})(?::(\d{2}))?$/i', $text, $matches)) {
            $seconds = (((int) ($matches[1] ?? 0) * 24 + (int) $matches[2]) * 3600)
                + ((int) $matches[3] * 60)
                + (int) ($matches[4] ?? 0);

            return round($seconds / 3600, 4);
        }

        if (preg_match('/(?:(\d+(?:\.\d+)?)\s*saat)?\s*(?:(\d+)\s*d[eə]qiq[eə])?/iu', $text, $matches)
            && trim((string) ($matches[0] ?? '')) !== '') {
            return round((float) ($matches[1] ?? 0) + ((int) ($matches[2] ?? 0) / 60), 4);
        }

        return null;
    }

    private function parseMileage(mixed $value): ?float
    {
        if (is_array($value)) {
            $text = trim((string) ($value['t'] ?? ''));

            if ($text !== '') {
                return $this->parseMileage($text);
            }

            return isset($value['v']) && is_numeric($value['v'])
                ? round(max(0, (float) $value['v'] / 1000), 3)
                : null;
        }

        $original = mb_strtolower(trim(str_replace(',', '.', (string) $value)));
        $text = str_replace(' ', '', $original);

        if (! preg_match('/-?\d+(?:\.\d+)?/', $text, $matches)) {
            return null;
        }

        $number = max(0, (float) $matches[0]);

        $isKilometers = str_contains($original, 'km') || str_contains($original, 'км');
        $isMeters = ! $isKilometers && (preg_match('/(?:^|\s)(?:m|м)(?:\s|$)/u', $original) === 1);

        return round($isMeters ? $number / 1000 : $number, 3);
    }

    private function parseTimestamp(mixed $cell, mixed $rowTimestamp, CarbonImmutable $date): ?CarbonImmutable
    {
        $numeric = is_numeric($rowTimestamp) ? (int) $rowTimestamp : null;

        if ($numeric === null && is_array($cell) && isset($cell['v']) && is_numeric($cell['v'])) {
            $numeric = (int) $cell['v'];
        }

        if ($numeric !== null && $numeric > 0) {
            return CarbonImmutable::createFromTimestampUTC($numeric)->setTimezone($this->timezone());
        }

        $text = trim($this->displayValue($cell));

        if ($text === '') {
            return null;
        }

        try {
            return preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $text)
                ? CarbonImmutable::parse($date->toDateString().' '.$text, $this->timezone())
                : CarbonImmutable::parse($text, $this->timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        return trim((string) $value);
    }

    private function unitId(array $row): ?string
    {
        foreach (['uid', 'unitId', 'unit_id'] as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function reportDate(array $report): CarbonImmutable
    {
        $from = $report['from'] ?? null;

        if ($from instanceof CarbonInterface) {
            return CarbonImmutable::instance($from)->setTimezone($this->timezone())->startOfDay();
        }

        return CarbonImmutable::parse((string) $from, $this->timezone())->startOfDay();
    }

    private function findIndex(array $values, array $candidates): ?int
    {
        foreach ($values as $index => $value) {
            if (in_array($value, $candidates, true)) {
                return $index;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? '');
    }

    private function safeJson(array $row): string
    {
        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function timezone(): string
    {
        return (string) config('daytime_efficiency.timezone', 'Asia/Baku');
    }
}
