<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class WialonShiftReportParser
{
    /**
     * @return array{records: array<int, array<string, mixed>>, tables: array<int, array<string, mixed>>, columns: array<int, array<int, string>>, raw: array<string, mixed>, unknown_rows: int}
     */
    public function parse(array $report): array
    {
        $records = [];
        $tables = [];
        $columns = [];
        $unknownRows = 0;
        $rawTable = null;
        $rawRow = null;

        foreach (($report['tables'] ?? []) as $tableIndex => $reportTable) {
            if (! is_array($reportTable)) {
                continue;
            }

            $table = is_array($reportTable['table'] ?? null) ? $reportTable['table'] : [];
            $headers = $this->headers($table);
            $rows = array_values(array_filter($reportTable['rows'] ?? [], 'is_array'));
            $tableRecords = [];
            $rawTable ??= $this->safeTable($table);
            $rowReport = [
                ...$report,
                '_current_table' => (string) (($table['name'] ?? null) ?: ($table['label'] ?? '')),
                '_current_table_index' => (int) ($reportTable['index'] ?? $tableIndex),
                '_current_table_position' => (int) $tableIndex,
                '_current_table_count' => count($report['tables'] ?? []),
                '_forced_shift_field' => match ($reportTable['_source_shift'] ?? null) {
                    'daytime' => 'daytime_hours',
                    'overtime' => 'overtime_hours',
                    default => null,
                },
            ];

            foreach ($this->flattenRows($rows, $headers) as $row) {
                if ($this->isGroupedParentRow($row, $headers)) {
                    continue;
                }

                $record = $this->parseRow($row, $headers, $rowReport);

                if ($record === null) {
                    continue;
                }

                $rawRow ??= $record['_raw'] ?? $this->safeRow($row);
                unset($record['_raw']);

                $tableRecords[] = $record;
                $records[] = $record;
            }

            $deduplicatedTableRecords = $this->deduplicateRecords($tableRecords);
            $tableUnknownRows = collect($deduplicatedTableRecords)
                ->filter(fn (array $row): bool => $row['daytime_hours'] === null || $row['overtime_hours'] === null)
                ->count();
            $unknownRows += $tableUnknownRows;

            $tables[] = [
                'index' => (int) ($reportTable['index'] ?? $tableIndex),
                'name' => (string) (($table['name'] ?? null) ?: ($table['label'] ?? '')),
                'rows' => count($rows),
                'parsed_records' => count($deduplicatedTableRecords),
                'unknown_rows' => $tableUnknownRows,
            ];
            $columns[(int) ($reportTable['index'] ?? $tableIndex)] = $headers;
        }

        $records = $this->deduplicateRecords($records);

        return [
            'records' => $records,
            'tables' => $tables,
            'columns' => $columns,
            'raw' => [
                'table' => $rawTable,
                'row' => $rawRow,
            ],
            'unknown_rows' => $unknownRows,
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, mixed>|null
     */
    public function parseRow(array $row, array $headers, array $report = []): ?array
    {
        $cells = $this->rowCells($row);
        $unitName = $this->unitName($row, $headers, $cells);
        $unitId = $this->extractWialonId($row);

        if ($unitName === '' && $unitId === null) {
            return null;
        }

        $date = $this->statDate($row, $headers, $cells, $report);

        if ($date === null) {
            return null;
        }

        $groupedShift = $this->groupedShiftHours($row, $headers, $cells);
        $directHours = $this->directShiftHours($headers, $cells);
        $hasShiftHours = array_key_exists('daytime_hours', $directHours) || array_key_exists('overtime_hours', $directHours);
        $reason = 'shift_values_unknown';

        if ($hasShiftHours) {
            $daytimeHours = $directHours['daytime_hours'] ?? null;
            $overtimeHours = $directHours['overtime_hours'] ?? null;
            $totalHours = $directHours['total_hours'] ?? null;
            $reason = $this->reason($daytimeHours, $overtimeHours, $directHours);
        } elseif ($groupedShift !== null) {
            $daytimeHours = $groupedShift['shift'] === 'daytime_hours' ? $groupedShift['hours'] : null;
            $overtimeHours = $groupedShift['shift'] === 'overtime_hours' ? $groupedShift['hours'] : null;
            $totalHours = $groupedShift['hours'];
            $reason = 'grouped_shift_row';
        } elseif (($tableShift = $this->tableShiftHours($row, $report, $headers, $cells)) !== null) {
            $daytimeHours = $tableShift['shift'] === 'daytime_hours' ? $tableShift['hours'] : null;
            $overtimeHours = $tableShift['shift'] === 'overtime_hours' ? $tableShift['hours'] : null;
            $totalHours = $tableShift['hours'];
            $reason = 'table_shift_row';
        } else {
            return null;
        }

        if ($daytimeHours !== null && $overtimeHours !== null) {
            $totalHours = $daytimeHours + $overtimeHours;
        }

        return [
            'wialon_unit_id' => $unitId,
            'unit_name' => $unitName,
            'statistic_date' => $date->toDateString(),
            'daytime_hours' => $daytimeHours === null ? null : round(max(0, $daytimeHours), 2),
            'overtime_hours' => $overtimeHours === null ? null : round(max(0, $overtimeHours), 2),
            'total_hours' => $totalHours === null ? null : round(max(0, $totalHours), 2),
            'source_intervals' => [],
            'source_table' => (string) ($report['_current_table'] ?? ''),
            'reason' => $reason,
            '_raw' => $this->safeRow($row),
        ];
    }

    public function daytimeStatus(?float $hours, ?float $totalHours = null): ?string
    {
        if ($hours === null) {
            return null;
        }

        if ($hours <= 0) {
            return 'no_data';
        }

        if ($hours < 1) {
            return 'less_than_1_hour';
        }

        if ($hours < 7) {
            return 'less_than_7_hours';
        }

        if ($hours <= 10) {
            return 'between_7_and_10_hours';
        }

        return 'over_10_hours';
    }

    public function parseDuration(mixed $value): ?float
    {
        if (is_array($value)) {
            $text = $this->normalizeCellValue($value['t'] ?? null);

            if ($text !== '') {
                $parsed = $this->parseDuration($text);

                if ($parsed !== null) {
                    return $parsed;
                }
            }

            $numeric = $value['v'] ?? null;

            if (is_numeric($numeric)) {
                $number = (float) $numeric;

                return $number > 24 ? $number / 3600 : $number;
            }

            $value = $text;
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

            return (($days * 24 + $hours) * 3600 + $minutes * 60 + $seconds) / 3600;
        }

        if (preg_match('/^\d+(?:[,.]\d+)?$/', $text)) {
            $number = (float) str_replace(',', '.', $text);

            return $number > 24 ? $number / 3600 : $number;
        }

        if (preg_match('/(?:(\d+(?:[,.]\d+)?)\s*saat)?\s*(?:(\d+)\s*d[eə]qiq[eə])?/iu', $text, $matches) && trim($matches[0] ?? '') !== '') {
            $hours = (float) str_replace(',', '.', $matches[1] ?? '0');
            $minutes = (int) ($matches[2] ?? 0);

            return $hours + ($minutes / 60);
        }

        return null;
    }

    public function parseTimestamp(mixed $value, ?CarbonInterface $date = null): ?CarbonInterface
    {
        if (is_array($value)) {
            $numeric = $value['v'] ?? null;

            if (is_numeric($numeric) && (int) $numeric > 1000000000) {
                return CarbonImmutable::createFromTimestamp((int) $numeric, $this->timezone());
            }

            $text = $this->normalizeCellValue($value['t'] ?? null);

            if ($text !== '') {
                $parsed = $this->parseTimestamp($text, $date);

                if ($parsed !== null) {
                    return $parsed;
                }
            }

            $value = $value['t'] ?? $numeric;
        }

        if (is_numeric($value) && (int) $value > 1000000000) {
            return CarbonImmutable::createFromTimestamp((int) $value, $this->timezone());
        }

        $text = $this->normalizeCellValue($value);

        if ($text === '') {
            return null;
        }

        if ($date !== null && preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $text)) {
            return CarbonImmutable::parse($date->toDateString().' '.$text, $this->timezone());
        }

        try {
            return CarbonImmutable::parse($text, $this->timezone());
        } catch (Throwable) {
            return null;
        }
    }

    public function normalizeCellValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        $text = trim((string) $value);

        return in_array($text, ['', '-', '-----', 'Total', 'Итого'], true) ? '' : preg_replace('/\s+/u', ' ', $text);
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function flattenRows(array $rows, array $headers = [], ?string $inheritedShiftLabel = null): array
    {
        $flat = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = $this->rowCells($row);
            $shiftLabel = $this->ownGroupedShiftLabel($headers, $cells) ?? $inheritedShiftLabel;

            if ($shiftLabel !== null) {
                $row['_shift_label'] = $shiftLabel;
            }

            $flat[] = $row;
            $children = $this->rowChildren($row);

            if ($children !== []) {
                $flat = array_merge($flat, $this->flattenRows($children, $headers, $shiftLabel));
            }
        }

        return $flat;
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

    private function unitName(array $row, array $headers, array $cells): string
    {
        foreach (['unit', 'unit_name', 'name', 'nm'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                $name = $this->normalizeCellValue($row[$key]);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        $index = $this->columnIndex($headers, ['unit', 'object', 'texnika', 'obyekt', 'объект']);

        if ($index !== null) {
            $name = $this->normalizeCellValue($cells[$index] ?? null);

            if ($name !== '') {
                return $name;
            }
        }

        $groupingIndex = $this->groupingIndex($headers);
        $inheritedShiftLabel = $this->normalizeCellValue($row['_shift_label'] ?? null);

        if ($inheritedShiftLabel !== '') {
            foreach ([($groupingIndex ?? 0) + 1, 1, 0] as $candidateIndex) {
                $name = $this->normalizeCellValue($cells[$candidateIndex] ?? null);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        if ($groupingIndex !== null && $this->shiftFieldFromLabel($this->normalizeCellValue($cells[$groupingIndex] ?? null)) !== null) {
            foreach ([$groupingIndex + 1, 1] as $candidateIndex) {
                $name = $this->normalizeCellValue($cells[$candidateIndex] ?? null);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $this->normalizeCellValue($cells[0] ?? null);
    }

    private function statDate(array $row, array $headers, array $cells, array $report): ?CarbonInterface
    {
        $index = $this->columnIndex($headers, ['date', 'tarix', 'gün', 'gun', 'day', 'дата']);
        $date = $index !== null ? $this->parseTimestamp($cells[$index] ?? null) : null;

        if ($date !== null) {
            return $date->timezone($this->timezone())->startOfDay();
        }

        $groupedDate = $this->groupedShiftDate($row, $headers, $cells);

        if ($groupedDate !== null) {
            return $groupedDate;
        }

        $tableShiftDate = $this->tableShiftDate($row, $headers, $cells, $report);

        if ($tableShiftDate !== null) {
            return $tableShiftDate;
        }

        $from = $report['from'] ?? null;
        $to = $report['to'] ?? null;

        if ($from instanceof CarbonInterface && $to instanceof CarbonInterface && $from->toDateString() === $to->toDateString()) {
            return $from->timezone($this->timezone())->startOfDay();
        }

        foreach ($cells as $cell) {
            if (! $this->looksLikeDateCell($cell)) {
                continue;
            }

            $date = $this->parseTimestamp($cell);

            if ($date !== null) {
                return $date->timezone($this->timezone())->startOfDay();
            }
        }

        return null;
    }

    private function looksLikeDateCell(mixed $value): bool
    {
        if (is_array($value) && isset($value['v']) && is_numeric($value['v']) && (int) $value['v'] > 1000000000) {
            return true;
        }

        $text = $this->normalizeCellValue($value);

        if ($text === '' || preg_match('/^\d{4}$/', $text) || preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $text)) {
            return false;
        }

        return (bool) preg_match('/\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4}/', $text);
    }

    /**
     * @return array{daytime_hours?: float|null, overtime_hours?: float|null, total_hours?: float|null}
     */
    private function directShiftHours(array $headers, array $cells): array
    {
        $dayIndex = $this->columnIndex($headers, ['смена 1', 'smena 1', 'shift 1', 'növbə 1', 'novbe 1', 'daytime', 'gündüz', 'gunduz', '08:00-17:59', '08:00 - 17:59', '08:00', '17:59', 'day shift', 'gün iş', 'gun is', 'dnn']);
        $overtimeIndex = $this->columnIndex($headers, ['смена 2', 'smena 2', 'shift 2', 'növbə 2', 'novbe 2', 'overtime', 'gecə', 'gece', 'night', '18:00-07:59', '18:00 - 07:59', '18:00', '07:59', 'növbə gecə', 'novbe gece']);
        $totalIndex = $this->columnIndex($headers, ['total', 'cəmi', 'cemi', 'ümumi', 'umumi', 'worked', 'iş saat', 'is saat', 'engine']);
        $result = [];

        if ($dayIndex !== null) {
            $result['daytime_hours'] = $this->parseDuration($cells[$dayIndex] ?? null);
        }

        if ($overtimeIndex !== null) {
            $result['overtime_hours'] = $this->parseDuration($cells[$overtimeIndex] ?? null);
        }

        if ($totalIndex !== null && $totalIndex !== $dayIndex && $totalIndex !== $overtimeIndex) {
            $result['total_hours'] = $this->parseDuration($cells[$totalIndex] ?? null);
        }

        return array_filter($result, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{shift: 'daytime_hours'|'overtime_hours', hours: float|null}|null
     */
    private function groupedShiftHours(array $row, array $headers, array $cells): ?array
    {
        $groupingIndex = $this->groupingIndex($headers);
        $label = $this->groupedShiftLabel($row, $headers, $cells);
        $shift = $this->shiftFieldFromLabel($label);

        if ($shift === null) {
            return null;
        }

        $hoursIndex = $this->columnIndex($headers, ['engine hours', 'engine hour', 'worked hours', 'work hours', 'motosaat', 'moto hours', 'is saat', 'saat']);

        if ($hoursIndex === null || $hoursIndex === $groupingIndex) {
            return [
                'shift' => $shift,
                'hours' => null,
            ];
        }

        return [
            'shift' => $shift,
            'hours' => $this->parseDuration($cells[$hoursIndex] ?? null),
        ];
    }

    /**
     * @return array{shift: 'daytime_hours'|'overtime_hours', hours: float|null}|null
     */
    private function tableShiftHours(array $row, array $report, array $headers, array $cells): ?array
    {
        $shift = $this->tableShiftField($report, $row, $headers, $cells);

        if ($shift === null) {
            return null;
        }

        $hoursIndex = $this->columnIndex($headers, ['engine hours', 'engine hour', 'worked hours', 'work hours', 'motosaat', 'moto hours', 'is saat', 'saat']);

        if ($hoursIndex === null) {
            return null;
        }

        return [
            'shift' => $shift,
            'hours' => $this->parseDuration($cells[$hoursIndex] ?? null),
        ];
    }

    private function tableShiftField(array $report, ?array $row = null, array $headers = [], array $cells = []): ?string
    {
        $forcedShift = $report['_forced_shift_field'] ?? null;

        if (in_array($forcedShift, ['daytime_hours', 'overtime_hours'], true)) {
            return $forcedShift;
        }

        $tableName = $this->normalizeHeader((string) ($report['_current_table'] ?? ''));
        $tableShift = null;

        if (str_contains($tableName, 'daytime') || str_contains($tableName, 'gunduz')) {
            $tableShift = 'daytime_hours';
        } elseif (str_contains($tableName, 'overtime') || str_contains($tableName, 'gece')) {
            $tableShift = 'overtime_hours';
        } elseif ((int) ($report['_current_table_count'] ?? 0) === 2) {
            $tableShift = ((int) ($report['_current_table_position'] ?? -1)) === 0
                ? 'daytime_hours'
                : 'overtime_hours';
        }

        if ($tableShift === null) {
            return null;
        }

        return ($row === null ? null : $this->shiftFieldFromLocalInterval($row, $headers, $cells, $report))
            ?? $tableShift;
    }

    private function groupedShiftDate(array $row, array $headers, array $cells): ?CarbonInterface
    {
        $label = $this->groupedShiftLabel($row, $headers, $cells);

        if ($this->shiftFieldFromLabel($label) === null) {
            return null;
        }

        if (! preg_match('/\((\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2})\)/', $label, $matches)) {
            return null;
        }

        return $this->parseTimestamp($matches[1])?->timezone($this->timezone())->startOfDay();
    }

    private function tableShiftDate(array $row, array $headers, array $cells, array $report): ?CarbonInterface
    {
        if ($this->tableShiftField($report, $row, $headers, $cells) === null) {
            return null;
        }

        $beginningIndex = $this->columnIndex($headers, ['beginning', 'start', 'from']);
        $dateContext = ($report['from'] ?? null) instanceof CarbonInterface
            ? $report['from']->timezone($this->timezone())->startOfDay()
            : null;
        $beginning = $this->rowIntervalTimestamp($row, 't1', $cells, $beginningIndex, $dateContext);

        if ($beginning === null) {
            return null;
        }

        $beginning = $beginning->timezone($this->timezone());

        return $beginning->startOfDay();
    }

    private function shiftFieldFromLocalInterval(array $row, array $headers, array $cells, array $report): ?string
    {
        $beginningIndex = $this->columnIndex($headers, ['beginning', 'start', 'from']);
        $endIndex = $this->columnIndex($headers, ['end', 'finish', 'to']);

        if ($beginningIndex === null) {
            return null;
        }

        $dateContext = ($report['from'] ?? null) instanceof CarbonInterface
            ? $report['from']->timezone($this->timezone())->startOfDay()
            : null;
        $beginning = $this->rowIntervalTimestamp($row, 't1', $cells, $beginningIndex, $dateContext)?->timezone($this->timezone());
        $end = $this->rowIntervalTimestamp($row, 't2', $cells, $endIndex, $dateContext)?->timezone($this->timezone());

        if ($beginning === null) {
            return null;
        }

        $dayStart = $this->secondsOfDay((string) config('fleet_efficiency.day_shift.start', '08:00:00'));
        $dayEnd = $this->secondsOfDay((string) config('fleet_efficiency.day_shift.end', '17:59:59'));
        $beginningSeconds = $this->carbonSecondsOfDay($beginning);
        $endSeconds = $end === null ? $beginningSeconds : $this->carbonSecondsOfDay($end);

        if ($beginningSeconds >= $dayStart && $endSeconds <= $dayEnd) {
            return 'daytime_hours';
        }

        if ($beginningSeconds > $dayEnd || $endSeconds < $dayStart) {
            return 'overtime_hours';
        }

        return null;
    }

    private function rowIntervalTimestamp(
        array $row,
        string $rowKey,
        array $cells,
        ?int $cellIndex,
        ?CarbonInterface $dateContext
    ): ?CarbonInterface {
        $rowTimestamp = $row[$rowKey] ?? null;

        if (is_numeric($rowTimestamp) && (int) $rowTimestamp > 1000000000) {
            return CarbonImmutable::createFromTimestamp((int) $rowTimestamp, $this->timezone());
        }

        return $cellIndex === null
            ? null
            : $this->parseTimestamp($cells[$cellIndex] ?? null, $dateContext);
    }

    private function secondsOfDay(string $time): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $matches)) {
            return 0;
        }

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) ($matches[3] ?? 0);
    }

    private function carbonSecondsOfDay(CarbonInterface $time): int
    {
        return ((int) $time->format('H') * 3600) + ((int) $time->format('i') * 60) + (int) $time->format('s');
    }

    private function groupedShiftLabel(array $row, array $headers, array $cells): string
    {
        $inherited = $this->normalizeCellValue($row['_shift_label'] ?? null);

        if ($inherited !== '') {
            return $inherited;
        }

        return $this->ownGroupedShiftLabel($headers, $cells) ?? '';
    }

    private function ownGroupedShiftLabel(array $headers, array $cells): ?string
    {
        $groupingIndex = $this->groupingIndex($headers);
        $label = $this->normalizeCellValue($cells[$groupingIndex ?? 0] ?? null);

        return $this->shiftFieldFromLabel($label) === null ? null : $label;
    }

    private function shiftFieldFromLabel(string $label): ?string
    {
        $normalized = $this->normalizeHeader($label);

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'daytime') || str_contains($normalized, 'shift 1') || str_contains($normalized, 'smena 1') || str_contains($normalized, 'novbe 1')) {
            return 'daytime_hours';
        }

        if (str_contains($normalized, 'overtime') || str_contains($normalized, 'shift 2') || str_contains($normalized, 'smena 2') || str_contains($normalized, 'novbe 2')) {
            return 'overtime_hours';
        }

        return null;
    }

    private function groupingIndex(array $headers): ?int
    {
        return $this->columnIndex($headers, ['grouping', 'group', 'shift', 'novbe']);
    }

    private function isGroupedParentRow(array $row, array $headers): bool
    {
        return $this->rowChildren($row) !== []
            && $this->ownGroupedShiftLabel($headers, $this->rowCells($row)) !== null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function columnIndex(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            foreach ($needles as $needle) {
                if ($this->headerMatchesNeedle($normalized, $needle)) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    private function headerMatchesNeedle(string $normalizedHeader, string $needle): bool
    {
        $normalizedNeedle = $this->normalizeHeader($needle);

        if ($normalizedNeedle === '') {
            return false;
        }

        if (mb_strlen($normalizedNeedle) <= 3) {
            return (bool) preg_match('/(?<![\pL\pN])'.preg_quote($normalizedNeedle, '/').'(?![\pL\pN])/u', $normalizedHeader);
        }

        return str_contains($normalizedHeader, $normalizedNeedle);
    }

    private function normalizeHeader(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function extractWialonId(array $row): ?string
    {
        foreach (['unitId', 'unit_id', 'itemId', 'uid', 'id'] as $key) {
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

            foreach (['i', 'id', 'unitId', 'itemId', 'uid'] as $key) {
                if (isset($cell[$key]) && is_scalar($cell[$key]) && (string) $cell[$key] !== '') {
                    return (string) $cell[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateRecords(array $records): array
    {
        $deduplicated = [];

        foreach ($records as $record) {
            $key = ($record['wialon_unit_id'] ?: mb_strtolower((string) $record['unit_name'])).'|'.$record['statistic_date'];

            if (! isset($deduplicated[$key])) {
                $deduplicated[$key] = $record;

                continue;
            }

            $existing = $deduplicated[$key];
            if ($this->isGroupedShiftRecord($existing) && $this->isGroupedShiftRecord($record)) {
                $deduplicated[$key] = $this->mergeGroupedShiftRecords($existing, $record);

                continue;
            }

            $existingKnown = $existing['daytime_hours'] !== null && $existing['overtime_hours'] !== null;
            $recordKnown = $record['daytime_hours'] !== null && $record['overtime_hours'] !== null;

            if (! $existingKnown && $recordKnown) {
                $deduplicated[$key] = $record;
            }
        }

        return array_values(array_map(fn (array $record): array => $this->finalizeGroupedShiftRecord($record), $deduplicated));
    }

    private function isGroupedShiftRecord(array $record): bool
    {
        return in_array($record['reason'] ?? '', ['grouped_shift_row', 'grouped_shift_rows', 'table_shift_row', 'table_shift_rows'], true);
    }

    private function mergeGroupedShiftRecords(array $existing, array $record): array
    {
        foreach (['daytime_hours', 'overtime_hours'] as $field) {
            if ($record[$field] === null) {
                continue;
            }

            $existing[$field] = round(max(0, (float) ($existing[$field] ?? 0) + (float) $record[$field]), 2);
        }

        $existing['total_hours'] = $this->sumKnownShiftHours($existing);
        $existing['wialon_unit_id'] = $existing['wialon_unit_id'] ?: $record['wialon_unit_id'];
        $existing['unit_name'] = $existing['unit_name'] ?: $record['unit_name'];
        $existing['reason'] = $this->mergedShiftReason($existing, $record);

        return $existing;
    }

    private function mergedShiftReason(array $existing, array $record): string
    {
        $reasons = [$existing['reason'] ?? null, $record['reason'] ?? null];

        return collect($reasons)->contains(fn (?string $reason): bool => str_starts_with((string) $reason, 'table_'))
            ? 'table_shift_rows'
            : 'grouped_shift_rows';
    }

    private function finalizeGroupedShiftRecord(array $record): array
    {
        if (! $this->isGroupedShiftRecord($record)) {
            return $record;
        }

        if ($record['daytime_hours'] === null && $record['overtime_hours'] === null) {
            return $record;
        }

        $record['daytime_hours'] = round(max(0, (float) ($record['daytime_hours'] ?? 0)), 2);
        $record['overtime_hours'] = round(max(0, (float) ($record['overtime_hours'] ?? 0)), 2);
        $record['total_hours'] = round($record['daytime_hours'] + $record['overtime_hours'], 2);
        $record['reason'] = 'grouped_shift_rows';

        return $record;
    }

    private function sumKnownShiftHours(array $record): ?float
    {
        if ($record['daytime_hours'] === null && $record['overtime_hours'] === null) {
            return null;
        }

        return round(max(0, (float) ($record['daytime_hours'] ?? 0) + (float) ($record['overtime_hours'] ?? 0)), 2);
    }

    private function reason(?float $daytimeHours, ?float $overtimeHours, array $directHours): string
    {
        if ($daytimeHours === null || $overtimeHours === null) {
            return 'shift_values_unknown';
        }

        return $directHours !== [] ? 'direct_shift_columns' : 'shift_values_unknown';
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
            'c' => array_slice($row['c'] ?? $row['cells'] ?? [], 0, 20),
            'n' => $row['n'] ?? null,
            'count' => $row['count'] ?? null,
            'level' => $row['level'] ?? null,
            'has_children' => $this->rowChildren($row) !== [],
            'shift_label' => $row['_shift_label'] ?? null,
            'keys' => array_keys($row),
        ];
    }

    private function timezone(): string
    {
        return (string) config('fleet_efficiency.timezone', 'Asia/Baku');
    }
}
