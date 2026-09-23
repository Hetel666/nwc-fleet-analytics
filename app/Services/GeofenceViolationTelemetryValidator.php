<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

class GeofenceViolationTelemetryValidator
{
    /** Report intervals can carry the last known position across days without messages. */
    public function filter(array $rows, WialonService $wialon, string $sid): array
    {
        $accepted = [];
        $counts = [];
        foreach ($rows as $row) {
            $unitId = $row['wialon_unit_id'] ?? null;
            if (! ctype_digit((string) $unitId) || (int) $unitId <= 0) {
                throw new RuntimeException('Telemetry validation requires a Wialon unit ID; snapshot unchanged.');
            }
            $timezone = config('app.timezone');
            $from = max(CarbonImmutable::parse($row['exited_at'], $timezone)->timestamp,
                CarbonImmutable::parse($row['report_period_from'], $timezone)->timestamp);
            $to = min(CarbonImmutable::parse($row['last_confirmed_at'], $timezone)->timestamp,
                CarbonImmutable::parse($row['report_period_to'], $timezone)->timestamp);
            if ($to < $from) {
                continue;
            }
            $key = $unitId.'|'.$from.'|'.$to;
            $count = $counts[$key] ??= $wialon->getDataMessageCount($unitId, $from, $to, $sid);
            if ($count === 0) {
                continue;
            }
            $row['source_payload']['telemetry_validation'] = [
                'message_count' => $count, 'from' => $from, 'to' => $to,
                'checked_at' => now($timezone)->toIso8601String(),
            ];
            $accepted[] = $row;
        }

        return $accepted;
    }
}
