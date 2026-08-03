<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\GeofenceViolationReportRow;
use App\Models\ProjectWialonGroup;
use App\Support\GeofenceExcludedGroups;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GeofenceViolationReportImporter
{
    public function __construct(private GeofenceExcludedGroups $excludedGroups) {}

    /**
     * Imports normalized rows produced only by the "Geofence Pozuntuları api" report.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, rejected: int}
     */
    public function import(array $rows, ?CarbonInterface $reportGeneratedAt = null): array
    {
        $reportGeneratedAt ??= now(config('app.timezone'));
        [$normalizedRows, $rejected] = $this->normalizeRows($rows, $reportGeneratedAt);

        if ($rejected > 0) {
            return ['imported' => 0, 'rejected' => $rejected];
        }

        DB::transaction(fn () => $this->persistRows($normalizedRows));
        $this->invalidateDashboardCache();

        return ['imported' => count($normalizedRows), 'rejected' => 0];
    }

    /**
     * Atomically replaces one project-group snapshot only after every accepted
     * source row has passed structural validation.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, rejected: int}
     */
    public function replaceGroupSnapshot(
        ProjectWialonGroup $group,
        CarbonInterface $from,
        CarbonInterface $to,
        array $rows,
        ?CarbonInterface $reportGeneratedAt = null,
        bool $replace = false
    ): array {
        if ($this->excludedGroups->isProjectWialonGroupExcluded($group)) {
            return ['imported' => 0, 'rejected' => 0];
        }

        $reportGeneratedAt ??= now(config('app.timezone'));
        [$normalizedRows, $rejected] = $this->normalizeRows($rows, $reportGeneratedAt, $group);

        if ($rejected > 0) {
            return ['imported' => 0, 'rejected' => $rejected];
        }

        DB::transaction(function () use ($group, $from, $to, $normalizedRows, $replace): void {
            $this->persistRows($normalizedRows);

            if (! $replace) {
                return;
            }

            $periodKeys = collect($normalizedRows)->pluck('period_key')->filter()->values();
            $query = GeofenceViolationReportRow::query()
                ->where('report_name', GeofenceViolationReportRow::REPORT_NAME)
                ->where(function ($query) use ($group): void {
                    $query->where('project_wialon_group_id', $group->id)
                        ->orWhere(function ($query) use ($group): void {
                            $query->whereNull('project_wialon_group_id')
                                ->where('project_id', $group->project_id)
                                ->where('ownership_type', $group->ownership_type);
                        });
                })
                ->where('exited_at', '<=', $to)
                ->where('last_confirmed_at', '>=', $from);

            if ($periodKeys->isNotEmpty()) {
                $query->whereNotIn('period_key', $periodKeys);
            }

            $query->delete();
        });

        $this->invalidateDashboardCache();

        return ['imported' => count($normalizedRows), 'rejected' => 0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function normalizeRows(
        array $rows,
        CarbonInterface $reportGeneratedAt,
        ?ProjectWialonGroup $expectedGroup = null
    ): array {
        $normalizedRows = [];
        $rejected = 0;

        foreach ($rows as $row) {
            if ($this->isAtOrBelowMinimumDuration($row)) {
                continue;
            }

            $normalized = $this->normalize($row, $reportGeneratedAt, $expectedGroup);

            if ($normalized === null) {
                $rejected++;

                continue;
            }

            if ($this->normalizedRowBelongsToExcludedGroup($normalized)) {
                continue;
            }

            $normalizedRows[$normalized['period_key']] = $normalized;
        }

        return [array_values($normalizedRows), $rejected];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persistRows(array $rows): void
    {
        foreach ($rows as $row) {
            GeofenceViolationReportRow::query()->updateOrCreate(
                ['period_key' => $row['period_key']],
                $row
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalize(
        array $row,
        CarbonInterface $reportGeneratedAt,
        ?ProjectWialonGroup $expectedGroup = null
    ): ?array {
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

        if ($expectedGroup !== null && $equipment !== null) {
            if ($equipment->project_id !== null && (int) $equipment->project_id !== (int) $expectedGroup->project_id) {
                return null;
            }

            if ($equipment->project_wialon_group_id !== null
                && (int) $equipment->project_wialon_group_id !== (int) $expectedGroup->id) {
                return null;
            }

            if (filled($equipment->ownership_type)
                && filled($expectedGroup->ownership_type)
                && $equipment->ownership_type !== $expectedGroup->ownership_type) {
                return null;
            }
        }

        if ((int) $durationSeconds <= (int) config('geofence_violations.minimum_duration_seconds', 10_800)) {
            return null;
        }

        $wialonUnitId = trim((string) ($row['wialon_unit_id'] ?? $equipment?->wialon_unit_id ?? ''));
        $periodKey = trim((string) ($row['period_key'] ?? ''));

        if ($periodKey === '') {
            $periodKey = sha1(implode('|', [
                GeofenceViolationReportRow::REPORT_NAME,
                $row['project_wialon_group_id'] ?? $expectedGroup?->id ?? '',
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
            'project_wialon_group_id' => filled($row['project_wialon_group_id'] ?? null)
                ? (int) $row['project_wialon_group_id']
                : $expectedGroup?->id ?? $equipment?->project_wialon_group_id,
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
            'report_period_from' => $this->timestamp($row['report_period_from'] ?? null),
            'report_period_to' => $this->timestamp($row['report_period_to'] ?? null),
            'report_generated_at' => $reportGeneratedAt,
            'source_payload' => $row['source_payload'] ?? $row,
        ];
    }

    /**
     * Wialon applies the business threshold before returning report rows. This
     * defensive check silently excludes stale rows that do not satisfy it.
     *
     * @param  array<string, mixed>  $row
     */
    private function isAtOrBelowMinimumDuration(array $row): bool
    {
        $duration = filter_var(
            $row['outside_duration_seconds'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        return $duration !== false
            && (int) $duration <= (int) config('geofence_violations.minimum_duration_seconds', 10_800);
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

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizedRowBelongsToExcludedGroup(array $row): bool
    {
        $projectWialonGroupId = (int) ($row['project_wialon_group_id'] ?? 0);
        $projectId = (int) ($row['project_id'] ?? 0);

        return ($projectWialonGroupId > 0 && in_array($projectWialonGroupId, $this->excludedGroups->projectWialonGroupIds(), true))
            || ($projectId > 0 && in_array($projectId, $this->excludedGroups->projectIdsWithOnlyExcludedGroups(), true));
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

    private function invalidateDashboardCache(): void
    {
        Cache::forever('geofence_violations:data_version', sprintf('%.6F', microtime(true)));
    }
}
