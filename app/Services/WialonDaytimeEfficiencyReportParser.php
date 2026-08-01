<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

class WialonDaytimeEfficiencyReportParser
{
    public function __construct(private WialonEfficiencyReportParser $baseParser) {}

    /** @return array{records: array<int, array<string, mixed>>, rows_received: int} */
    public function parse(array $report): array
    {
        $parsed = $this->baseParser->parse($report);
        $recordIndex = 0;

        foreach ($report['tables'] ?? [] as $reportTable) {
            $table = $reportTable['table'] ?? [];
            $headers = array_map(fn ($value): string => trim((string) $value), $table['header'] ?? []);
            $types = $table['header_type'] ?? [];
            $engineHoursIndex = $this->columnIndex($headers, ['engine hours'], $types, ['duration']);
            $groupingIndex = $this->columnIndex($headers, ['grouping', 'группировка'], $types, ['']);

            if ($engineHoursIndex === null || $groupingIndex === null) {
                continue;
            }

            $beginIndex = $this->columnIndex($headers, ['begin', 'start', 'начало'], $types, ['time_begin']);
            $endIndex = $this->columnIndex($headers, ['end', 'конец'], $types, ['time_end']);

            foreach ($reportTable['rows'] ?? [] as $row) {
                if (! is_array($row) || ! isset($parsed['records'][$recordIndex])) {
                    continue;
                }

                $cells = $row['c'] ?? $row['cells'] ?? [];
                $parsed['records'][$recordIndex]['source_table_index'] = (int) ($reportTable['index'] ?? 0);
                $parsed['records'][$recordIndex]['started_at'] = $this->localDateTime(
                    $beginIndex === null ? null : ($cells[$beginIndex] ?? null),
                );
                $parsed['records'][$recordIndex]['ended_at'] = $this->localDateTime(
                    $endIndex === null ? null : ($cells[$endIndex] ?? null),
                );
                $recordIndex++;
            }
        }

        return $parsed;
    }

    private function localDateTime(mixed $value): ?CarbonImmutable
    {
        if (is_array($value) && is_numeric($value['v'] ?? null)) {
            return CarbonImmutable::createFromTimestamp((int) $value['v'], 'UTC')->timezone($this->timezone());
        }

        if (is_array($value) && filled($value['t'] ?? null)) {
            try {
                return CarbonImmutable::parse((string) $value['t'], $this->timezone());
            } catch (Throwable) {
                // Fall back to the scalar value below.
            }
        }

        try {
            $text = trim((string) $value);

            return $text === '' ? null : CarbonImmutable::parse($text, $this->timezone());
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int, string> $headers
     * @param  array<int, string>  $names
     * @param  array<int, string>  $types
     * @param  array<int, string>  $wantedTypes
     */
    private function columnIndex(array $headers, array $names, array $types, array $wantedTypes): ?int
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

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function timezone(): string
    {
        return (string) config('historical_recalculation.timezone', 'Asia/Baku');
    }
}
