<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\ProjectWialonGroup;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class FleetShiftDailyStatsSyncService
{
    public const SOURCE = 'wialon_shift_report';

    public function __construct(private FleetEfficiencyService $efficiency) {}

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function syncGroup(ProjectWialonGroup $group, CarbonInterface $from, CarbonInterface $to, array $records, array $context = [], ?string $unitFilter = null, bool $details = false): array
    {
        $equipment = $this->equipmentForGroup($group, $unitFilter);
        $recordMap = $this->recordMap($records);
        $dates = collect(iterator_to_array(CarbonPeriod::create($from->toDateString(), $to->toDateString())))
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->values();
        $totals = $this->emptyTotals();
        $detailRows = [];

        foreach ($equipment as $item) {
            foreach ($dates as $date) {
                $record = $this->findRecord($recordMap, $item, $date);
                $saved = $this->saveStat($group, $item, $date, $record, $context);
                $status = $saved->day_status ?: FleetEfficiencyService::STATUS_NO_DATA;
                $dataAvailable = (bool) $saved->data_available;
                $hasOvertime = $saved->has_overtime === null ? null : (bool) $saved->has_overtime;

                $totals['unit_days']++;
                $totals['daytime_rows'] += $saved->daytime_hours !== null ? 1 : 0;
                $totals['overtime_rows'] += $hasOvertime === true ? 1 : 0;
                $totals['unknown_rows'] += $dataAvailable ? 0 : 1;
                $totals['saved_records'] += $saved->wasRecentlyCreated ? 1 : 0;
                $totals['updated_records'] += $saved->wasRecentlyCreated ? 0 : 1;
                $totals['status_counts'][$status] = ($totals['status_counts'][$status] ?? 0) + 1;

                if ($details) {
                    $detailRows[] = [
                        'date' => $date,
                        'unit' => $item->name,
                        'wialon_id' => $item->wialon_unit_id,
                        'type' => FleetVehicleType::display($item->type?->name),
                        'ownership' => $item->ownership_type,
                        'project' => $item->project?->name,
                        'daytime_hours' => $saved->daytime_hours,
                        'overtime_hours' => $saved->overtime_hours,
                        'total_hours' => $saved->total_hours,
                        'day_status' => $status,
                        'has_overtime' => $hasOvertime,
                        'source' => $saved->calculation_source,
                        'reason' => $record['reason'] ?? ($record ? 'matched_report_row' : 'missing_report_row'),
                    ];
                }
            }
        }

        $totals['equipment_count'] = $equipment->count();
        $totals['details'] = $detailRows;

        return $totals;
    }

    /**
     * @return Collection<int, Equipment>
     */
    public function equipmentForGroup(ProjectWialonGroup $group, ?string $unitFilter = null): Collection
    {
        $unitFilter = trim((string) $unitFilter);
        $allowed = config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', []));

        return Equipment::query()
            ->with(['type:id,name', 'project:id,name'])
            ->where('active', true)
            ->visibleInDashboard()
            ->classifiedForDashboard()
            ->where('project_id', $group->project_id)
            ->where('ownership_type', $group->ownership_type)
            ->when($unitFilter !== '', function ($query) use ($unitFilter): void {
                $query->where(function ($query) use ($unitFilter): void {
                    $query->where('wialon_unit_id', $unitFilter)
                        ->orWhere('id', ctype_digit($unitFilter) ? (int) $unitFilter : 0)
                        ->orWhere('name', 'like', '%'.$unitFilter.'%');
                });
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Equipment $item): bool => in_array(FleetVehicleType::slug($item->type?->name), $allowed, true))
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @param  array<string, mixed>  $context
     */
    public function saveStat(ProjectWialonGroup $group, Equipment $equipment, string $date, ?array $record, array $context): EquipmentDailyStat
    {
        $statDate = CarbonImmutable::parse($date, config('fleet_efficiency.timezone', 'Asia/Baku'))->toDateString();
        $daytime = $record['daytime_hours'] ?? null;
        $overtime = $record['overtime_hours'] ?? null;
        $total = $record['total_hours'] ?? null;
        $daytimeSeconds = $this->nullableSeconds($record['daytime_seconds'] ?? null, $daytime);
        $overtimeSeconds = $this->nullableSeconds($record['overtime_seconds'] ?? null, $overtime);
        $totalSeconds = $this->nullableSeconds($record['total_seconds'] ?? null, $total);
        $dataAvailable = $record !== null && $daytimeSeconds !== null && $overtimeSeconds !== null;
        $hasOvertime = $overtimeSeconds === null ? null : $overtimeSeconds > 0;
        $existing = EquipmentDailyStat::query()
            ->where('stat_date', $statDate)
            ->where('equipment_id', $equipment->id)
            ->first();

        if (! $dataAvailable && $existing && $this->shouldPreserveExistingStat($existing)) {
            return $existing;
        }

        if ($daytimeSeconds !== null && $overtimeSeconds !== null) {
            $totalSeconds = $daytimeSeconds + $overtimeSeconds;
            $daytime = $daytimeSeconds / 3600;
            $overtime = $overtimeSeconds / 3600;
            $total = $totalSeconds / 3600;
        } elseif ($daytime !== null && $overtime !== null) {
            $total = (float) $daytime + (float) $overtime;
            $totalSeconds = (int) round($total * 3600);
        }

        $dayStatus = $dataAvailable
            ? $this->efficiency->efficiencyStatusForSeconds($daytimeSeconds, $totalSeconds)
            : null;

        return EquipmentDailyStat::updateOrCreate(
            ['stat_date' => $statDate, 'equipment_id' => $equipment->id],
            [
                'project_id' => $equipment->project_id,
                'ownership_type' => $equipment->ownership_type,
                'worked_hours' => $total === null ? 0 : round((float) $total, 2),
                'daytime_hours' => $daytime === null ? null : round((float) $daytime, 2),
                'daytime_seconds' => $daytimeSeconds,
                'overtime_hours' => $overtime === null ? null : round((float) $overtime, 2),
                'overtime_seconds' => $overtimeSeconds,
                'total_hours' => $total === null ? null : round((float) $total, 2),
                'total_seconds' => $totalSeconds,
                'day_status' => $dayStatus,
                'has_overtime' => $hasOvertime,
                'data_available' => $dataAvailable,
                'daytime_data_available' => $daytime !== null,
                'overtime_data_available' => $overtime !== null,
                'calculation_source' => self::SOURCE,
                'calculation_status' => $dataAvailable ? 'ok' : 'shift_unknown',
                'report_resource_id' => (string) ($context['resource_id'] ?? ''),
                'report_template_id' => (string) ($context['template_id'] ?? ''),
                'source_group_id' => (string) $group->wialon_group_id,
                'source_intervals_json' => $record['source_intervals'] ?? [],
                'calculated_at' => CarbonImmutable::now(config('fleet_efficiency.timezone', 'Asia/Baku')),
            ]
        );
    }

    private function shouldPreserveExistingStat(EquipmentDailyStat $existing): bool
    {
        if ($existing->calculation_source === self::SOURCE) {
            return (bool) $existing->data_available
                && $existing->daytime_hours !== null
                && $existing->overtime_hours !== null;
        }

        return $existing->worked_hours !== null || $existing->distance_km !== null;
    }

    private function nullableSeconds(mixed $seconds, mixed $hours): ?int
    {
        if ($seconds !== null && $seconds !== '') {
            return max(0, (int) $seconds);
        }

        if ($hours === null || $hours === '') {
            return null;
        }

        return (int) round(max(0, (float) $hours) * 3600);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function recordMap(array $records): array
    {
        $map = [];

        foreach ($records as $record) {
            $date = (string) ($record['statistic_date'] ?? '');

            if ($date === '') {
                continue;
            }

            $unitId = trim((string) ($record['wialon_unit_id'] ?? ''));
            $unitName = mb_strtolower(trim((string) ($record['unit_name'] ?? '')));

            if ($unitId !== '') {
                $map['id:'.$unitId.'|'.$date] = $record;
            }

            if ($unitName !== '') {
                $map['name:'.$unitName.'|'.$date] = $record;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $recordMap
     */
    private function findRecord(array $recordMap, Equipment $equipment, string $date): ?array
    {
        $unitId = trim((string) $equipment->wialon_unit_id);
        $unitName = mb_strtolower(trim((string) $equipment->name));

        return ($unitId !== '' ? ($recordMap['id:'.$unitId.'|'.$date] ?? null) : null)
            ?? ($unitName !== '' ? ($recordMap['name:'.$unitName.'|'.$date] ?? null) : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTotals(): array
    {
        return [
            'equipment_count' => 0,
            'unit_days' => 0,
            'daytime_rows' => 0,
            'overtime_rows' => 0,
            'unknown_rows' => 0,
            'saved_records' => 0,
            'updated_records' => 0,
            'status_counts' => [],
            'details' => [],
        ];
    }
}
