<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DiagnoseFleetOvertime extends Command
{
    protected $signature = 'fleet:diagnose-overtime
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--project= : Project id}
        {--ownership= : Ownership: NWC, ICARE, nwc, icare}
        {--unit= : Equipment id or Wialon unit id}
        {--details : Show matching unit-day rows}';

    protected $description = 'Diagnose stored overtime values for efficiency widgets.';

    public function handle(): int
    {
        $query = $this->baseQuery();

        $total = (clone $query)->count();
        $positive = (clone $query)->where('equipment_daily_stats.overtime_hours', '>', 0)->count();
        $zero = (clone $query)->whereNotNull('equipment_daily_stats.overtime_hours')->where('equipment_daily_stats.overtime_hours', '=', 0)->count();
        $null = (clone $query)->whereNull('equipment_daily_stats.overtime_hours')->count();
        $minMax = (clone $query)
            ->selectRaw('MIN(equipment_daily_stats.overtime_hours) as min_overtime, MAX(equipment_daily_stats.overtime_hours) as max_overtime')
            ->first();

        $this->table(['Metric', 'Value'], [
            ['unit-day rows', $total],
            ['overtime > 0', $positive],
            ['overtime = 0', $zero],
            ['null overtime', $null],
            ['min overtime', $minMax?->min_overtime ?? 'NULL'],
            ['max overtime', $minMax?->max_overtime ?? 'NULL'],
        ]);

        $this->newLine();
        $this->line('By ownership');
        $this->table(['Ownership', 'Rows', 'Overtime > 0', 'Overtime = 0', 'Null overtime'], $this->groupRows($query, 'equipment_daily_stats.ownership_type'));

        $this->newLine();
        $this->line('By project');
        $this->table(['Project', 'Rows', 'Overtime > 0', 'Overtime = 0', 'Null overtime'], $this->groupRows($query, 'projects.name'));

        $this->newLine();
        $this->line('By equipment type');
        $this->table(['Type', 'Rows', 'Overtime > 0', 'Overtime = 0', 'Null overtime'], $this->groupRows($query, 'equipment_types.name'));

        $examples = (clone $query)
            ->orderByDesc('equipment_daily_stats.overtime_hours')
            ->orderByDesc('equipment_daily_stats.stat_date')
            ->limit(10)
            ->get([
                'equipment_daily_stats.stat_date',
                'equipments.name as unit',
                'equipments.wialon_unit_id',
                'equipment_daily_stats.ownership_type',
                'projects.name as project',
                'equipment_daily_stats.worked_hours',
                'equipment_daily_stats.overtime_hours',
                'equipment_daily_stats.calculation_source',
            ])
            ->map(fn ($row): array => [
                $row->stat_date,
                $row->unit,
                $row->wialon_unit_id,
                $row->ownership_type,
                $row->project,
                $row->worked_hours,
                $row->overtime_hours ?? 'NULL',
                $row->calculation_source,
            ])
            ->all();

        $this->newLine();
        $this->line('Examples');
        $this->table(['Date', 'Unit', 'Wialon ID', 'Ownership', 'Project', 'Day hours', 'Overtime hours', 'Source'], $examples);

        if ($this->option('details')) {
            $this->newLine();
            $this->line('Details');
            $details = (clone $query)
                ->orderBy('equipment_daily_stats.stat_date')
                ->orderBy('equipments.name')
                ->limit(500)
                ->get([
                    'equipment_daily_stats.stat_date',
                    'equipments.name as unit',
                    'equipments.wialon_unit_id',
                    'equipment_daily_stats.ownership_type',
                    'projects.name as project',
                    'equipment_daily_stats.worked_hours',
                    'equipment_daily_stats.overtime_hours',
                    'equipment_daily_stats.calculation_source',
                ])
                ->map(fn ($row): array => [
                    $row->stat_date,
                    $row->unit,
                    $row->ownership_type,
                    $row->project,
                    $row->worked_hours,
                    $row->overtime_hours ?? 'NULL',
                    $row->worked_hours,
                    'yes',
                    $row->calculation_source,
                    $this->reason($row->overtime_hours),
                ])
                ->all();

            $this->table(['date', 'unit', 'ownership', 'project', 'day_hours', 'overtime_hours', 'total_hours', 'data_available', 'source', 'reason'], $details);
        }

        return self::SUCCESS;
    }

    private function baseQuery(): Builder
    {
        $query = EquipmentDailyStat::query()
            ->join('equipments', 'equipments.id', '=', 'equipment_daily_stats.equipment_id')
            ->leftJoin('projects', 'projects.id', '=', 'equipment_daily_stats.project_id')
            ->leftJoin('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->whereIn('equipment_daily_stats.ownership_type', [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE]);

        if ($this->option('from')) {
            $query->where('equipment_daily_stats.stat_date', '>=', $this->option('from'));
        }

        if ($this->option('to')) {
            $query->where('equipment_daily_stats.stat_date', '<=', $this->option('to'));
        }

        if ($this->option('project')) {
            $query->where('equipment_daily_stats.project_id', (int) $this->option('project'));
        }

        $ownership = mb_strtoupper((string) $this->option('ownership'));
        if (in_array($ownership, [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE], true)) {
            $query->where('equipment_daily_stats.ownership_type', $ownership);
        }

        if ($this->option('unit')) {
            $unit = (string) $this->option('unit');
            $query->where(function (Builder $query) use ($unit): void {
                $query->where('equipments.id', is_numeric($unit) ? (int) $unit : 0)
                    ->orWhere('equipments.wialon_unit_id', $unit);
            });
        }

        return $query;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function groupRows(Builder $query, string $column): array
    {
        return (clone $query)
            ->selectRaw("COALESCE({$column}, '—') as label")
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('SUM(CASE WHEN equipment_daily_stats.overtime_hours > 0 THEN 1 ELSE 0 END) as overtime_count')
            ->selectRaw('SUM(CASE WHEN equipment_daily_stats.overtime_hours = 0 THEN 1 ELSE 0 END) as zero_count')
            ->selectRaw('SUM(CASE WHEN equipment_daily_stats.overtime_hours IS NULL THEN 1 ELSE 0 END) as null_count')
            ->groupBy(DB::raw("COALESCE({$column}, '—')"))
            ->orderByDesc('overtime_count')
            ->orderByDesc('rows_count')
            ->limit(30)
            ->get()
            ->map(fn ($row): array => [
                $row->label,
                (int) $row->rows_count,
                (int) $row->overtime_count,
                (int) $row->zero_count,
                (int) $row->null_count,
            ])
            ->all();
    }

    private function reason(mixed $overtimeHours): string
    {
        if ($overtimeHours === null) {
            return 'overtime_unknown';
        }

        return (float) $overtimeHours > 0 ? 'included_overtime' : 'no_overtime';
    }
}
