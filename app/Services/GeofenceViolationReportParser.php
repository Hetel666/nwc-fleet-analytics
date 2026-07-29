<?php

namespace App\Services;

use App\Models\GeofenceViolationReportRow;
use App\Models\ProjectWialonGroup;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class GeofenceViolationReportParser
{
    /**
     * @return array{records: array<int, array<string, mixed>>, source_rows: int, skipped_types: int, malformed_rows: int}
     */
    public function parse(
        array $report,
        ProjectWialonGroup $group,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        $records = [];
        $sourceRows = 0;
        $skippedTypes = 0;
        $malformedRows = 0;

        foreach ($report['tables'] ?? [] as $tablePayload) {
            $table = $tablePayload['table'] ?? [];
            $indexes = $this->columnIndexes($table);

            foreach ($tablePayload['rows'] ?? [] as $row) {
                if (! is_array($row) || ! $this->isOutsideGeofencesRow($row, $indexes['grouping'])) {
                    continue;
                }

                foreach ($this->leafRows($row['r'] ?? []) as $unitRow) {
                    $sourceRows++;
                    $record = $this->parseUnitRow($unitRow, $indexes, $group, $from, $to);

                    if ($record === null) {
                        $malformedRows++;

                        continue;
                    }

                    if (! $record['allowed_type']) {
                        $skippedTypes++;

                        continue;
                    }

                    unset($record['allowed_type']);
                    $records[] = $record;
                }
            }
        }

        return [
            'records' => $records,
            'source_rows' => $sourceRows,
            'skipped_types' => $skippedTypes,
            'malformed_rows' => $malformedRows,
        ];
    }

    /**
     * @param  array<string, int>  $indexes
     * @return array<string, mixed>|null
     */
    private function parseUnitRow(
        array $row,
        array $indexes,
        ProjectWialonGroup $group,
        CarbonInterface $from,
        CarbonInterface $to
    ): ?array {
        $cells = is_array($row['c'] ?? null) ? $row['c'] : [];
        $equipmentName = $this->cellText($cells[$indexes['grouping']] ?? null);
        $reportedType = $this->cellText($cells[$indexes['equipment_type']] ?? null);
        $equipmentType = FleetVehicleType::display($reportedType);
        $exitedAt = $this->timestamp($cells[$indexes['entry_time']] ?? null, $row['t1'] ?? null);
        $lastConfirmedAt = $this->timestamp($cells[$indexes['exit_time']] ?? null, $row['t2'] ?? null);
        $durationSeconds = $this->durationSeconds($cells[$indexes['duration']] ?? null);

        if ($equipmentName === ''
            || $reportedType === ''
            || $exitedAt === null
            || $lastConfirmedAt === null
            || $lastConfirmedAt->lt($exitedAt)
            || $durationSeconds === null
        ) {
            return null;
        }

        $wialonUnitId = filled($row['uid'] ?? null) ? (string) $row['uid'] : null;
        $activeTolerance = max(1, (int) config('geofence_violations.active_end_tolerance_seconds', 300));
        $isCurrentReport = $to->timestamp >= now(config('app.timezone'))->subSeconds($activeTolerance)->timestamp;
        $isActive = $isCurrentReport && abs($to->timestamp - $lastConfirmedAt->timestamp) <= $activeTolerance;

        return [
            'period_key' => sha1(implode('|', [
                GeofenceViolationReportRow::REPORT_NAME,
                $wialonUnitId ?: mb_strtolower($equipmentName),
                $exitedAt->timestamp,
            ])),
            'wialon_unit_id' => $wialonUnitId,
            'equipment_name' => $equipmentName,
            'equipment_type' => $equipmentType,
            'ownership_type' => $group->ownership_type,
            'project_id' => $group->project_id,
            'project_name' => $group->project?->name,
            'last_project_geofence' => null,
            'exited_at' => $exitedAt->toDateTimeString(),
            'last_confirmed_at' => $lastConfirmedAt->toDateTimeString(),
            'ended_at' => $isActive ? null : $lastConfirmedAt->toDateTimeString(),
            'outside_duration_seconds' => $durationSeconds,
            'last_location' => $this->location($cells[$indexes['exit_time']] ?? null),
            'is_active' => $isActive,
            'allowed_type' => in_array($equipmentType, config('geofence_violations.allowed_equipment_types', []), true),
            'source_payload' => [
                'resource_id' => config('geofence_violations.resource_id'),
                'template_id' => config('geofence_violations.template_id'),
                'group_id' => (string) $group->wialon_group_id,
                'project_id' => $group->project_id,
                'ownership_type' => $group->ownership_type,
                'report_from' => $from->toIso8601String(),
                'report_to' => $to->toIso8601String(),
                'row' => $row,
            ],
        ];
    }

    /**
     * @return array{grouping: int, equipment_type: int, entry_time: int, exit_time: int, duration: int}
     */
    private function columnIndexes(array $table): array
    {
        $headers = array_map(fn (mixed $value): string => mb_strtolower(trim((string) $value)), $table['header'] ?? []);
        $types = array_map(fn (mixed $value): string => mb_strtolower(trim((string) $value)), $table['header_type'] ?? []);

        return [
            'grouping' => $this->index($types, ['grouping'], $this->index($headers, ['grouping'], 0)),
            'equipment_type' => $this->index($types, ['user_column'], 1),
            'entry_time' => $this->index($types, ['time_begin'], 3),
            'exit_time' => $this->index($types, ['time_end'], 4),
            'duration' => $this->index($types, ['duration_in'], 5),
        ];
    }

    private function index(array $values, array $needles, int $fallback): int
    {
        foreach ($values as $index => $value) {
            foreach ($needles as $needle) {
                if ($value === $needle || str_contains($value, $needle)) {
                    return (int) $index;
                }
            }
        }

        return $fallback;
    }

    private function isOutsideGeofencesRow(array $row, int $groupingIndex): bool
    {
        $cells = is_array($row['c'] ?? null) ? $row['c'] : [];
        $value = mb_strtolower($this->cellText($cells[$groupingIndex] ?? null));

        return in_array($value, [
            'out of geofences',
            'outside geofences',
            'outside all geofences',
            'вне геозон',
            'geozonalardan kənar',
        ], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leafRows(array $rows): array
    {
        $leaves = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $children = is_array($row['r'] ?? null) ? $row['r'] : [];

            if ($children !== []) {
                array_push($leaves, ...$this->leafRows($children));
            } else {
                $leaves[] = $row;
            }
        }

        return $leaves;
    }

    private function cellText(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['t'] ?? $value['v'] ?? '';
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $cell, mixed $fallback): ?CarbonImmutable
    {
        $numeric = is_array($cell) ? ($cell['v'] ?? null) : null;
        $numeric = is_numeric($numeric) ? $numeric : $fallback;

        if (is_numeric($numeric) && (int) $numeric > 1_000_000_000) {
            return CarbonImmutable::createFromTimestamp((int) $numeric, config('app.timezone'));
        }

        $text = $this->cellText($cell);

        if ($text === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($text, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function durationSeconds(mixed $value): ?int
    {
        if (is_array($value) && is_numeric($value['v'] ?? null)) {
            return max(0, (int) round((float) $value['v']));
        }

        $text = mb_strtolower($this->cellText($value));

        if ($text === '') {
            return null;
        }

        $days = 0;

        if (preg_match('/(\d+)\s*(?:days?|gün|дн(?:я|ей)?)/u', $text, $matches) === 1) {
            $days = (int) $matches[1];
        }

        if (preg_match('/(\d{1,2}):(\d{2}):(\d{2})/', $text, $matches) !== 1) {
            return null;
        }

        return ($days * 86_400) + ((int) $matches[1] * 3_600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }

    private function location(mixed $value): ?string
    {
        if (! is_array($value) || ! is_numeric($value['y'] ?? null) || ! is_numeric($value['x'] ?? null)) {
            return null;
        }

        return number_format((float) $value['y'], 6, '.', '').', '.number_format((float) $value['x'], 6, '.', '');
    }
}
