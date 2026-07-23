<?php

namespace App\Services;

use App\Models\DailyUnitAggregate;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\ProjectWialonGroup;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WialonDashboardDatasetSyncService
{
    public function __construct(
        private WialonService $wialon,
        private DashboardDataVersion $dataVersion,
    ) {}

    public function syncDailyEngineHoursReport(array $filters, bool $force = false): array
    {
        $filters = $this->normalizeFilters($filters);

        if (! $filters['project_id'] || ! $filters['ownership_type'] || $filters['from'] !== $filters['to']) {
            throw new \InvalidArgumentException('Daily report sync requires one project, one ownership type and one date.');
        }

        $ownershipType = $filters['ownership_type'];
        $group = ProjectWialonGroup::query()
            ->where('project_id', $filters['project_id'])
            ->where('ownership_type', $ownershipType)
            ->first();

        if (! $group) {
            throw new \RuntimeException('Wialon group is not configured for the selected project and ownership type.');
        }

        return $this->syncDailyGroup($filters, $group, $force, false);
    }

    public function syncDailyOwnershipEngineHoursReport(array $filters, bool $force = false): array
    {
        $filters = $this->normalizeFilters($filters);

        if ($filters['project_id'] || ! $filters['ownership_type'] || $filters['from'] !== $filters['to']) {
            throw new \InvalidArgumentException('Root ownership report sync requires one ownership type, one date and no project.');
        }

        $group = $this->rootOwnershipWialonGroup($filters['ownership_type']);

        if (! $group) {
            throw new \RuntimeException('Root Wialon ownership group is not configured.');
        }

        return $this->syncDailyGroup($filters, $group, $force, true);
    }

    private function syncDailyGroup(array $filters, object $group, bool $force, bool $rootGroup): array
    {
        $settings = $this->wialonReportSettings();
        $syncKey = $rootGroup
            ? $this->wialonDailyRootEngineHoursSyncKey($filters, $settings, $group)
            : $this->wialonDailyEngineHoursSyncKey($filters, $settings, $group);
        $previousSync = json_decode((string) Setting::query()->where('key', $syncKey)->value('value'), true);

        if (! $force && ($previousSync['status'] ?? null) === 'success') {
            return [
                'status' => 'skipped',
                'date' => $filters['from'],
                'project_id' => $rootGroup ? null : $filters['project_id'],
                'ownership_type' => $filters['ownership_type'],
                'equipment_count' => (int) ($previousSync['equipment_count'] ?? 0),
            ];
        }

        $equipment = $this->equipmentQuery($filters)->get();
        $equipmentIds = $equipment->pluck('id')->all();
        $hoursByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $mileageByEquipmentId = $equipment->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0.0])->all();
        $engineHoursEquipmentIds = [];
        $mileageEquipmentIds = [];
        $equipmentIdByReportKey = $equipment->mapWithKeys(fn (Equipment $item): array => [
            $this->reportUnitKey($item->ownership_type, $item->name) => $item->id,
        ])->all();

        $reportData = $this->getDailyWialonEngineHours(
            $filters,
            collect([$filters['ownership_type'] => $group]),
            $settings,
            $hoursByEquipmentId,
            $mileageByEquipmentId,
            $engineHoursEquipmentIds,
            $mileageEquipmentIds,
            $equipmentIdByReportKey,
            [$filters['ownership_type']],
            max(5, (int) config('fleet.wialon.report_stats_sync_timeout', 90))
        );

        if ($reportData === null) {
            throw new \RuntimeException($rootGroup
                ? 'Wialon root ownership daily engine hours report could not be generated.'
                : 'Wialon daily engine hours report could not be generated.');
        }

        $reportedEquipmentIds = array_map('intval', $reportData['reported_equipment_ids'] ?? []);
        $equipmentById = $equipment->keyBy('id');
        $date = $filters['from'];

        DB::transaction(function () use ($reportedEquipmentIds, $equipmentById, $reportData, $date, $equipmentIds): void {
            if ($equipmentIds !== []) {
                EquipmentDailyStat::query()
                    ->where('stat_date', $date)
                    ->whereIn('equipment_id', $equipmentIds)
                    ->where('calculation_source', 'wialon_engine_hours_report')
                    ->delete();

                DailyUnitAggregate::query()
                    ->where('date', $date)
                    ->whereIn('equipment_id', $equipmentIds)
                    ->delete();
            }

            foreach ($reportedEquipmentIds as $equipmentId) {
                $item = $equipmentById->get($equipmentId);

                if (! $item || ! $item->wialon_unit_id) {
                    continue;
                }

                $workedHours = round((float) ($reportData['hours'][$equipmentId] ?? 0.0), 2);
                $distanceKm = round((float) ($reportData['mileage'][$equipmentId] ?? 0.0), 2);
                $utilization = $item->planned_daily_hours > 0
                    ? min(100, ($workedHours / (float) $item->planned_daily_hours) * 100)
                    : 0;
                $dailyStat = EquipmentDailyStat::updateOrCreate(
                    ['stat_date' => $date, 'equipment_id' => $equipmentId],
                    [
                        'project_id' => $item->project_id,
                        'ownership_type' => $item->ownership_type,
                        'worked_hours' => $workedHours,
                        'distance_km' => $distanceKm,
                        'utilization_percent' => round($utilization, 2),
                        'calculation_source' => 'wialon_engine_hours_report',
                        'calculation_status' => 'success',
                    ]
                );

                DailyUnitAggregate::updateOrCreate(
                    ['date' => $date, 'unit_id' => $item->wialon_unit_id],
                    [
                        'equipment_id' => $equipmentId,
                        'project_id' => $item->project_id,
                        'equipment_type_id' => $item->equipment_type_id,
                        'ownership_type' => $item->ownership_type,
                        'engine_hours' => $workedHours,
                        'mileage' => $distanceKm,
                        'geofence_outside_hours' => round(((float) $dailyStat->outside_geofence_minutes) / 60, 2),
                    ]
                );
            }
        });

        Setting::updateOrCreate(
            ['key' => $syncKey],
            [
                'value' => json_encode([
                    'status' => 'success',
                    'equipment_count' => count($reportedEquipmentIds),
                    'synced_at' => now(config('app.timezone'))->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
            ]
        );

        $this->dataVersion->bump();

        return [
            'status' => 'synced',
            'date' => $date,
            'project_id' => $rootGroup ? null : $filters['project_id'],
            'ownership_type' => $filters['ownership_type'],
            'equipment_count' => count($reportedEquipmentIds),
        ];
    }

    /**
     * @return array{from: string, to: string, project_id: int|null, ownership_type: string|null, equipment_type_id: int|null}
     */
    private function normalizeFilters(array $filters): array
    {
        $from = Carbon::parse($filters['date_from'] ?? $filters['from'] ?? now(config('app.timezone'))->startOfMonth())->toDateString();
        $to = Carbon::parse($filters['date_to'] ?? $filters['to'] ?? now(config('app.timezone')))->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $ownership = $filters['ownership_type'] ?? $filters['ownership'] ?? null;
        $ownership = $ownership ? mb_strtoupper(trim((string) $ownership)) : null;

        if (! in_array($ownership, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $ownership = null;
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => isset($filters['project_id']) && $filters['project_id'] !== '' ? (int) $filters['project_id'] : null,
            'ownership_type' => $ownership,
            'equipment_type_id' => isset($filters['equipment_type_id']) && $filters['equipment_type_id'] !== '' ? (int) $filters['equipment_type_id'] : null,
        ];
    }

    private function equipmentQuery(array $filters): Builder
    {
        return Equipment::query()
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('equipments.project_id', $projectId))
            ->when($filters['equipment_type_id'], fn ($query, $typeId) => $query->where('equipments.equipment_type_id', $typeId))
            ->when($filters['ownership_type'], fn ($query, $ownershipType) => $query->where('equipments.ownership_type', $ownershipType));
    }

    private function getDailyWialonEngineHours(
        array $filters,
        $groups,
        array $settings,
        array $hoursByEquipmentId,
        array $mileageByEquipmentId,
        array $engineHoursEquipmentIds,
        array $mileageEquipmentIds,
        array $equipmentIdByReportKey,
        ?array $ownershipTypes = null,
        ?int $requestTimeout = null
    ): ?array {
        $reportDaysByEquipmentId = [];
        $reportedEquipmentIds = [];
        $successfulReports = 0;
        $ownershipTypes ??= [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE];

        foreach ($ownershipTypes as $ownershipType) {
            $group = $groups->get($ownershipType);

            if (! $group) {
                continue;
            }

            foreach (CarbonPeriod::create($filters['from'], $filters['to']) as $date) {
                $day = Carbon::parse($date->toDateString(), config('app.timezone'));

                try {
                    $report = $this->wialon->getReportTablesRows(
                        $settings['resource_id'],
                        $settings['template_id'],
                        $group->wialon_group_id,
                        $day->copy()->startOfDay()->timestamp,
                        $day->copy()->endOfDay()->timestamp,
                        500,
                        16777216,
                        false,
                        max(5, $requestTimeout ?? (int) config('fleet.wialon.daily_engine_hours_report_timeout', 30))
                    );
                } catch (Throwable $exception) {
                    Log::warning('Wialon daily engine hours report failed', [
                        'project_id' => $filters['project_id'],
                        'ownership_type' => $ownershipType,
                        'date' => $day->toDateString(),
                        'message' => $exception->getMessage(),
                    ]);

                    continue;
                }

                $engineTable = $this->wialonReportTableByKind($report['tables'] ?? [], 'engine', true);

                if ($engineTable === null) {
                    Log::warning('Wialon daily engine hours table missing', [
                        'project_id' => $filters['project_id'],
                        'ownership_type' => $ownershipType,
                        'date' => $day->toDateString(),
                        'tables' => collect($report['tables'] ?? [])->map(fn (array $item): string => (string) (($item['table']['label'] ?? null) ?: ($item['table']['name'] ?? '')))->all(),
                    ]);

                    continue;
                }

                $successfulReports++;
                $engineHoursIndex = $this->engineHoursColumnIndex($engineTable['table'] ?? []);
                $mileageIndex = $this->mileageColumnIndex($engineTable['table'] ?? []);

                foreach ($engineTable['rows'] ?? [] as $row) {
                    $cells = $row['c'] ?? [];
                    $unitName = $this->reportCellText($cells[0] ?? null);

                    if ($unitName === '') {
                        continue;
                    }

                    $equipmentId = $equipmentIdByReportKey[$this->reportUnitKey($ownershipType, $unitName)] ?? null;

                    if (! $equipmentId) {
                        continue;
                    }

                    $engineSeconds = $this->parseWialonEngineHoursToSeconds($cells[$engineHoursIndex] ?? null);
                    $mileageKm = $this->parseWialonMileageToKm($cells[$mileageIndex] ?? null);

                    if ($engineSeconds !== null) {
                        $hoursByEquipmentId[$equipmentId] += $engineSeconds / 3600;
                        $reportDaysByEquipmentId[$equipmentId][$day->toDateString()] = true;
                        $engineHoursEquipmentIds[(int) $equipmentId] = true;
                        $reportedEquipmentIds[(int) $equipmentId] = true;
                    }

                    if ($mileageKm !== null) {
                        $mileageByEquipmentId[$equipmentId] += $mileageKm;
                        $mileageEquipmentIds[(int) $equipmentId] = true;
                        $reportedEquipmentIds[(int) $equipmentId] = true;
                    }
                }
            }
        }

        if ($successfulReports === 0) {
            return null;
        }

        return [
            'hours' => $hoursByEquipmentId,
            'mileage' => $mileageByEquipmentId,
            'stat_days' => array_map('count', $reportDaysByEquipmentId),
            'engine_hours_equipment_ids' => array_keys($engineHoursEquipmentIds),
            'mileage_equipment_ids' => array_keys($mileageEquipmentIds),
            'reported_equipment_ids' => array_keys($reportedEquipmentIds),
        ];
    }

    private function wialonReportSettings(): array
    {
        $settings = Setting::query()
            ->whereIn('key', ['wialon_resource_id', 'wialon_report_template_id'])
            ->pluck('value', 'key');

        return [
            'resource_id' => (int) ($settings->get('wialon_resource_id') ?: config('fleet.wialon.engine_hours_report_resource_id')),
            'template_id' => (int) ($settings->get('wialon_report_template_id') ?: config('fleet.wialon.engine_hours_report_template_id')),
        ];
    }

    private function wialonDailyEngineHoursSyncKey(array $filters, array $settings, object $group): string
    {
        return 'wialon_daily_engine_sync:'.sha1(json_encode([
            'version' => 1,
            'resource_id' => $settings['resource_id'],
            'template_id' => $settings['template_id'],
            'project_id' => $filters['project_id'],
            'ownership_type' => $group->ownership_type,
            'group_id' => $group->wialon_group_id,
            'date' => $filters['from'],
        ]));
    }

    private function wialonDailyRootEngineHoursSyncKey(array $filters, array $settings, object $group): string
    {
        return 'wialon_daily_root_engine_sync:'.sha1(json_encode([
            'version' => 1,
            'resource_id' => $settings['resource_id'],
            'template_id' => $settings['template_id'],
            'scope' => 'root_ownership_group',
            'ownership_type' => $group->ownership_type,
            'group_id' => $group->wialon_group_id,
            'date' => $filters['from'],
        ]));
    }

    private function rootOwnershipWialonGroup(string $ownershipType): ?object
    {
        return $this->rootOwnershipWialonGroups()->get($ownershipType);
    }

    private function rootOwnershipWialonGroups()
    {
        return collect([
            Equipment::OWNERSHIP_NWC => (object) [
                'ownership_type' => Equipment::OWNERSHIP_NWC,
                'wialon_group_id' => (string) config('fleet.wialon.nwc_group_id'),
                'name' => '+NWC+',
            ],
            Equipment::OWNERSHIP_ICARE => (object) [
                'ownership_type' => Equipment::OWNERSHIP_ICARE,
                'wialon_group_id' => (string) config('fleet.wialon.icare_group_id'),
                'name' => '+ICARE+',
            ],
        ])->filter(fn (object $group): bool => trim($group->wialon_group_id) !== '');
    }

    private function wialonReportTableByKind(array $tables, string $kind, bool $strict = false): ?array
    {
        foreach ($tables as $table) {
            $meta = $table['table'] ?? [];
            $text = mb_strtolower(trim(implode(' ', array_filter([
                $meta['label'] ?? '',
                $meta['name'] ?? '',
                ...($meta['header'] ?? []),
            ]))));

            if ($kind === 'engine' && str_contains($text, 'engine') && ! str_contains($text, 'geofence')) {
                return $table;
            }
        }

        if ($strict) {
            return null;
        }

        return $tables[0] ?? null;
    }

    private function engineHoursColumnIndex(?array $table): int
    {
        return $this->reportColumnIndex($table, [
            'engine hours',
            'motor saati',
            'moto hours',
            'm/h',
            'worked hours',
            'duration',
            'saat',
        ], ['duration'], 3);
    }

    private function mileageColumnIndex(?array $table): int
    {
        return $this->reportColumnIndex($table, ['mileage'], ['mileage'], 4);
    }

    private function reportColumnIndex(?array $table, array $headers, array $headerTypes, int $default): int
    {
        $headers = array_map(fn (string $header): string => mb_strtolower($header), $headers);
        $headerTypes = array_map(fn (string $type): string => mb_strtolower($type), $headerTypes);

        foreach (($table['header'] ?? []) as $index => $header) {
            if (in_array(mb_strtolower(trim((string) $header)), $headers, true)) {
                return (int) $index;
            }
        }

        foreach (($table['header_type'] ?? []) as $index => $type) {
            if (in_array(mb_strtolower(trim((string) $type)), $headerTypes, true)) {
                return (int) $index;
            }
        }

        return $default;
    }

    private function reportUnitKey(string $ownershipType, string $unitName): string
    {
        return $ownershipType.'|'.mb_strtolower(trim(preg_replace('/\s+/', ' ', $unitName) ?? $unitName));
    }

    private function reportCellText(mixed $cell): string
    {
        if (is_array($cell)) {
            $cell = $cell['t'] ?? $cell['v'] ?? '';
        }

        return trim((string) $cell);
    }

    private function parseWialonEngineHoursToSeconds(mixed $cell): ?int
    {
        if (is_array($cell)) {
            $textValue = $cell['t'] ?? null;

            if ($textValue !== null) {
                $parsedText = $this->parseWialonEngineHoursToSeconds($textValue);

                if ($parsedText !== null) {
                    return $parsedText;
                }
            }

            return $this->parseWialonEngineHoursToSeconds($cell['v'] ?? null);
        }

        if ($cell === null) {
            return null;
        }

        if (is_int($cell) || is_float($cell)) {
            return max(0, (int) round((float) $cell));
        }

        $value = trim((string) $cell);

        if ($value === '' || in_array($value, ['-', '-----'], true)) {
            return null;
        }

        if (preg_match('/^(?:(\d+)\s+day[s]?\s+)?(\d+):(\d{2})(?::(\d{2}))?$/i', $value, $matches)) {
            $days = (int) ($matches[1] ?? 0);
            $hours = (int) $matches[2];
            $minutes = (int) $matches[3];
            $seconds = (int) ($matches[4] ?? 0);

            return max(0, (($days * 24 + $hours) * 3600) + ($minutes * 60) + $seconds);
        }

        if (preg_match('/^\d+(?:[,.]\d+)?$/', $value)) {
            return max(0, (int) round($this->parseReportNumber($value) * 3600));
        }

        return null;
    }

    private function parseWialonMileageToKm(mixed $cell): ?float
    {
        if (is_array($cell)) {
            $textValue = $cell['t'] ?? null;

            if ($textValue !== null) {
                $parsedText = $this->parseWialonMileageToKm($textValue);

                if ($parsedText !== null) {
                    return $parsedText;
                }
            }

            return $this->parseWialonMileageToKm($cell['v'] ?? null);
        }

        if ($cell === null) {
            return null;
        }

        if (is_int($cell) || is_float($cell)) {
            return max(0.0, (float) $cell);
        }

        $value = trim((string) $cell);

        if ($value === '' || in_array($value, ['-', '-----'], true)) {
            return null;
        }

        $normalized = preg_replace('/[^\d,.\-]+/u', '', str_replace(["\xc2\xa0", ' '], '', $value)) ?? '';

        if ($normalized === '' || ! preg_match('/-?\d+(?:[,.]\d+)?/', $normalized)) {
            return null;
        }

        return max(0.0, $this->parseReportNumber($value));
    }

    private function parseReportNumber(mixed $cell): float
    {
        if (is_array($cell)) {
            $cell = $cell['v'] ?? $cell['t'] ?? 0;
        }

        if (is_numeric($cell)) {
            return (float) $cell;
        }

        $value = trim((string) $cell);

        if ($value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]+/u', '', str_replace(["\xc2\xa0", ' '], '', $value)) ?? '';

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            $normalized = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $normalized))
                : str_replace(',', '', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        return preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches)
            ? (float) $matches[0]
            : 0.0;
    }
}
