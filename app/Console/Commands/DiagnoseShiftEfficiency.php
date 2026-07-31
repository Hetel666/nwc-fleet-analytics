<?php

namespace App\Console\Commands;

use App\Models\EquipmentDailyStat;
use App\Services\FleetEfficiencyService;
use App\Support\FleetVehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DiagnoseShiftEfficiency extends Command
{
    protected $signature = 'fleet:diagnose-shift-efficiency
        {--date= : Single date in YYYY-MM-DD format}
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--group= : Source Wialon group ID}
        {--project= : Project database ID}
        {--ownership= : NWC or ICARE}
        {--unit= : Wialon/local ID or unit name}
        {--details : Show per-unit-day rows}';

    protected $description = 'Diagnose saved shift-based efficiency data in equipment_daily_stats.';

    public function handle(FleetEfficiencyService $efficiency): int
    {
        [$from, $to] = $this->period();
        $settings = [
            'resource_id' => (string) config('fleet.wialon.shift_report_resource_id', ''),
            'template_id' => (string) config('fleet.wialon.shift_report_template_id', ''),
            'template_name' => (string) config('fleet.wialon.shift_daytime_report_template_name', 'Qrup report daytime (api)')
                .' + '
                .(string) config('fleet.wialon.shift_overtime_report_template_name', 'Qrup report overtime (api)'),
        ];
        $rows = $this->rows($from, $to);
        $totals = [
            'unit_day_total' => $rows->count(),
            'daytime_calculated' => $rows->whereNotNull('daytime_hours')->count(),
            'daytime_unknown' => $rows->whereNull('daytime_hours')->count(),
            'overtime_calculated' => $rows->whereNotNull('overtime_hours')->count(),
            'overtime_positive' => $rows->filter(fn (EquipmentDailyStat $row): bool => (float) $row->overtime_hours > 0)->count(),
            'overtime_zero' => $rows->filter(fn (EquipmentDailyStat $row): bool => $row->overtime_hours !== null && (float) $row->overtime_hours <= 0)->count(),
            'overtime_unknown' => $rows->whereNull('overtime_hours')->count(),
        ];

        $this->table(
            ['Metric', 'Value'],
            [
                ['report resource ID', $settings['resource_id']],
                ['report template ID', $settings['template_id']],
                ['report template name', $settings['template_name']],
                ['group', $this->option('group') ?: 'all'],
                ['period', $from->toDateString().' - '.$to->toDateString()],
                ['unit-day total', $totals['unit_day_total']],
                ['daytime calculated', $totals['daytime_calculated']],
                ['daytime unknown', $totals['daytime_unknown']],
                ['overtime calculated', $totals['overtime_calculated']],
                ['overtime positive', $totals['overtime_positive']],
                ['overtime zero', $totals['overtime_zero']],
                ['overtime unknown', $totals['overtime_unknown']],
            ]
        );

        $this->newLine();
        $this->line('Date counts');
        $this->table(
            ['Date', 'Unit-days', 'Daytime calculated', 'Overtime positive', 'No data'],
            $rows
                ->groupBy(fn (EquipmentDailyStat $row): string => $row->stat_date?->toDateString() ?? 'unknown')
                ->map(fn ($items, string $date): array => [
                    $date,
                    $items->count(),
                    $items->whereNotNull('daytime_hours')->count(),
                    $items->filter(fn (EquipmentDailyStat $row): bool => (float) $row->overtime_hours > 0)->count(),
                    $items->filter(fn (EquipmentDailyStat $row): bool => ! (bool) $row->data_available)->count(),
                ])
                ->values()
                ->all()
        );
        $this->newLine();
        $this->line('Status counts');
        $this->table(['Value', 'Count'], $this->counts($rows, fn (EquipmentDailyStat $row): string => $row->day_status ?: FleetEfficiencyService::STATUS_NO_DATA));
        $this->line('Ownership counts');
        $this->table(['Value', 'Count'], $this->counts($rows, fn (EquipmentDailyStat $row): string => $row->ownership_type ?: 'unknown'));
        $this->line('Vehicle type counts');
        $this->table(['Value', 'Count'], $this->counts($rows, fn (EquipmentDailyStat $row): string => FleetVehicleType::display($row->equipment?->type?->name)));
        $this->line('Project counts');
        $this->table(['Value', 'Count'], $this->counts($rows, fn (EquipmentDailyStat $row): string => $row->project?->name ?? $row->equipment?->project?->name ?? 'unknown'));

        if ($this->option('details')) {
            $this->printDetails($rows, $efficiency);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('fleet_efficiency.timezone', 'Asia/Baku');
        $date = $this->option('date');
        $from = $date
            ? CarbonImmutable::parse((string) $date, $timezone)->startOfDay()
            : CarbonImmutable::parse((string) ($this->option('from') ?: now($timezone)->subDay()->toDateString()), $timezone)->startOfDay();
        $to = $date
            ? CarbonImmutable::parse((string) $date, $timezone)->endOfDay()
            : CarbonImmutable::parse((string) ($this->option('to') ?: $from->toDateString()), $timezone)->endOfDay();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function rows(CarbonImmutable $from, CarbonImmutable $to)
    {
        $unit = trim((string) $this->option('unit'));
        $ownership = strtoupper(trim((string) $this->option('ownership')));
        $allowed = config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', []));

        return EquipmentDailyStat::query()
            ->with(['equipment.type:id,name', 'equipment.project:id,name', 'project:id,name'])
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->option('group'), fn ($query, string $group) => $query->where('source_group_id', trim($group)))
            ->when($this->option('project'), fn ($query, string $project) => $query->where('project_id', (int) $project))
            ->when(in_array($ownership, ['NWC', 'ICARE'], true), fn ($query) => $query->where('ownership_type', $ownership))
            ->when($unit !== '', function ($query) use ($unit): void {
                $query->whereHas('equipment', function ($query) use ($unit): void {
                    $query->where('wialon_unit_id', $unit)
                        ->orWhere('id', ctype_digit($unit) ? (int) $unit : 0)
                        ->orWhere('name', 'like', '%'.$unit.'%');
                });
            })
            ->orderBy('stat_date')
            ->orderBy('equipment_id')
            ->get()
            ->filter(fn (EquipmentDailyStat $row): bool => in_array(FleetVehicleType::slug($row->equipment?->type?->name), $allowed, true))
            ->values();
    }

    private function counts($rows, callable $callback): array
    {
        return $rows
            ->groupBy($callback)
            ->map(fn ($items, string $key): array => [$key, $items->count()])
            ->values()
            ->all();
    }

    private function printDetails($rows, FleetEfficiencyService $efficiency): void
    {
        $this->table(
            ['Date', 'Unit', 'Type', 'Ownership', 'Project', 'Daytime', 'Overtime', 'Total', 'Day status', 'Has overtime', 'Source', 'Reason'],
            $rows->map(fn (EquipmentDailyStat $row): array => [
                $row->stat_date?->toDateString(),
                $row->equipment?->name,
                FleetVehicleType::display($row->equipment?->type?->name),
                $row->ownership_type,
                $row->project?->name ?? $row->equipment?->project?->name,
                $row->daytime_hours ?? 'NULL',
                $row->overtime_hours ?? 'NULL',
                $row->total_hours ?? 'NULL',
                $row->day_status ?: FleetEfficiencyService::STATUS_NO_DATA,
                $row->has_overtime === null ? 'unknown' : ((bool) $row->has_overtime ? 'yes' : 'no'),
                $row->calculation_source,
                $row->calculation_status,
            ])->all()
        );
    }
}
