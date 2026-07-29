<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\GeofenceViolationReportRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GeofenceViolationReportImporter
{
    /**
     * Imports normalized rows produced only by the "Geofence Pozuntuları api" report.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, rejected: int}
     */
    public function import(array $rows, ?CarbonInterface $reportGeneratedAt = null): array
    {
        $imported = 0;
        $rejected = 0;
        $reportGeneratedAt ??= now(config('app.timezone'));

        DB::transaction(function () use ($rows, $reportGeneratedAt, &$imported, &$rejected): void {
            foreach ($rows as $row) {
                $normalized = $this->normalize($row, $reportGeneratedAt);

                if ($normalized === null) {
                    $rejected++;

                    continue;
                }

                GeofenceViolationReportRow::query()->updateOrCreate(
                    ['period_key' => $normalized['period_key']],
                    $normalized
                );
                $imported++;
            }
        });

        return compact('imported', 'rejected');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalize(array $row, CarbonInterface $reportGeneratedAt): ?array
    {
        $equipment = $this->resolveEquipment($row);
        $equipmentName = trim((string) ($row['equipment_name'] ?? $equipment?->name ?? ''));
        $equipmentType = trim((string) ($row['equipment_type'] ?? $equipment?->type?->name ?? ''));
        $exitedAt = $this->timestamp($row['exited_at'] ?? null);
        $lastConfirmedAt = $this->timestamp($row['last_confirmed_at'] ?? null);
        $endedAt = $this->timestamp($row['ended_at'] ?? null);
        $durationSeconds = filter_var(
            $row['outside_duration_seconds'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        if ($equipmentName === ''
            || $equipmentType === ''
            || $exitedAt === null
            || $lastConfirmedAt === null
            || $lastConfirmedAt->lt($exitedAt)
            || $durationSeconds === false
        ) {
            return null;
        }

        $continuousSpanSeconds = $lastConfirmedAt->timestamp - $exitedAt->timestamp;
        $durationToleranceSeconds = max(0, (int) config(
            'geofence_violations.duration_tolerance_seconds',
            5
        ));

        if (abs($continuousSpanSeconds - (int) $durationSeconds) > $durationToleranceSeconds) {
            return null;
        }

        $wialonUnitId = trim((string) ($row['wialon_unit_id'] ?? $equipment?->wialon_unit_id ?? ''));
        $periodKey = trim((string) ($row['period_key'] ?? ''));

        if ($periodKey === '') {
            $periodKey = sha1(implode('|', [
                GeofenceViolationReportRow::REPORT_NAME,
                $wialonUnitId !== '' ? $wialonUnitId : mb_strtolower($equipmentName),
                $exitedAt->timestamp,
            ]));
        }

        return [
            'report_name' => GeofenceViolationReportRow::REPORT_NAME,
            'period_key' => $periodKey,
            'equipment_id' => $equipment?->id,
            'project_id' => filled($row['project_id'] ?? null)
                ? (int) $row['project_id']
                : $equipment?->project_id,
            'wialon_unit_id' => $wialonUnitId !== '' ? $wialonUnitId : null,
            'equipment_name' => $equipmentName,
            'equipment_type' => $equipmentType,
            'ownership_type' => $row['ownership_type'] ?? $equipment?->ownership_type,
            'project_name' => $row['project_name'] ?? $equipment?->project?->name,
            'last_project_geofence' => $this->nullableString($row['last_project_geofence'] ?? null),
            'exited_at' => $exitedAt,
            'last_confirmed_at' => $lastConfirmedAt,
            'ended_at' => $endedAt,
            'outside_duration_seconds' => (int) $durationSeconds,
            'last_location' => $this->nullableString($row['last_location'] ?? null),
            'is_active' => (bool) ($row['is_active'] ?? $endedAt === null),
            'report_generated_at' => $reportGeneratedAt,
            'source_payload' => $row['source_payload'] ?? $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveEquipment(array $row): ?Equipment
    {
        $wialonUnitId = trim((string) ($row['wialon_unit_id'] ?? ''));

        if ($wialonUnitId !== '') {
            $equipment = Equipment::query()
                ->with(['type', 'project'])
                ->where('wialon_unit_id', $wialonUnitId)
                ->first();

            if ($equipment instanceof Equipment) {
                return $equipment;
            }
        }

        $name = trim((string) ($row['equipment_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $matches = Equipment::query()
            ->with(['type', 'project'])
            ->where('name', $name)
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
