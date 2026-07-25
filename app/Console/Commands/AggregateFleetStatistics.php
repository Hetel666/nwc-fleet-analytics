<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AggregateFleetStatistics extends Command
{
    private const ENGINE_HOURS_TYPES = [
        'excavator',
        'road roller',
        'loader',
        'bulldozer',
        'backhoe loader',
        'road grader',
        'crane',
        'forklift',
        'paver',
        'tractor',
        'skid steer loader',
    ];

    protected $signature = 'fleet:aggregate-statistics
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--all-projects : Aggregate every active project}
        {--project= : Project database ID}';

    protected $description = 'Build period summary from stored fleet statistics.';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $from = Carbon::parse($this->option('from') ?: '2026-01-01', config('app.timezone'))->toDateString();
        $to = Carbon::parse($this->option('to') ?: now(config('app.timezone'))->toDateString(), config('app.timezone'))->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (! $this->option('all-projects') && ! $this->option('project')) {
            $this->error('Use --all-projects or --project=<project_id>.');

            return self::INVALID;
        }

        $periodDays = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $ownershipCounts = $this->ownershipCounts();
        $typeRows = $this->equipmentTypeDistribution();
        $totals = $this->periodTotals($from, $to);
        $averages = $this->periodAverages($from, $to);
        $categories = $this->workHourCategories($from, $to);
        $least = $this->topEquipment($from, $to, 'asc');
        $most = $this->topEquipment($from, $to, 'desc');
        $projects = $this->projectStats($from, $to);

        $summary = [
            'from' => $from,
            'to' => $to,
            'period_days' => $periodDays,
            'equipment_counts' => $ownershipCounts,
            'type_distribution' => $typeRows,
            'totals' => $totals,
            'averages' => $averages,
            'categories' => $categories,
            'top_10_least' => $least,
            'top_10_most' => $most,
            'projects' => $projects,
            'generator_excluded' => $this->excludedGeneratorCount(),
            'processed_objects' => $this->processedObjectCount($from, $to),
            'seconds' => round(microtime(true) - $startedAt, 1),
        ];

        Setting::query()->updateOrCreate(
            ['key' => 'fleet:aggregate-statistics:'.sha1(json_encode([$from, $to, $this->option('project') ?: 'all']))],
            ['value' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_secret' => false]
        );

        $this->line('Aggregate report');
        $this->table(['Metric', 'Value'], [
            ['period_days', $summary['period_days']],
            ['processed_objects', $summary['processed_objects']],
            ['generator_excluded', $summary['generator_excluded']],
            ['NWC', $ownershipCounts[Equipment::OWNERSHIP_NWC] ?? 0],
            ['ICARE', $ownershipCounts[Equipment::OWNERSHIP_ICARE] ?? 0],
            ['total_engine_hours', $totals['engine_hours']],
            ['total_mileage', $totals['mileage']],
            ['avg_engine_hours', $averages['engine_hours']],
            ['avg_mileage_dump_truck', $averages['mileage']],
            ['seconds', $summary['seconds']],
        ]);

        $this->newLine();
        $this->line('Work hour categories');
        $this->table(['Ownership', '1 saatdan az', '1-7 saat', '7-10 saat', '10 saatdan cox'], collect($categories)->map(fn (array $row, string $ownership): array => [
            $ownership,
            $row['less_than_1'],
            $row['from_1_to_7'],
            $row['from_7_to_10'],
            $row['overtime'],
        ])->all());

        $this->newLine();
        $this->line('Top 10 az isleyenler');
        $this->table(['#', 'Texnika', 'Ownership', 'Nov', 'Saat'], $least);

        $this->newLine();
        $this->line('Top 10 cox isleyenler');
        $this->table(['#', 'Texnika', 'Ownership', 'Nov', 'Saat'], $most);

        $this->newLine();
        $this->line('Project statistics');
        $this->table(['Project', 'NWC', 'ICARE', 'Engine hours', 'Mileage'], $projects);

        return self::SUCCESS;
    }

    private function statsBase(string $from, string $to): Builder
    {
        return EquipmentDailyStat::query()
            ->join('equipments', 'equipments.id', '=', 'equipment_daily_stats.equipment_id')
            ->leftJoin('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->leftJoin('projects', 'projects.id', '=', 'equipment_daily_stats.project_id')
            ->whereBetween('equipment_daily_stats.stat_date', [$from, $to])
            ->where('equipments.active', true)
            ->where(function (Builder $query): void {
                $query->where('equipments.excluded_from_dashboard', false)
                    ->orWhereNull('equipments.excluded_from_dashboard');
            })
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('equipment_daily_stats.project_id', (int) $projectId));
    }

    private function ownershipCounts(): array
    {
        return Equipment::query()
            ->where('active', true)
            ->visibleInDashboard()
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('project_id', (int) $projectId))
            ->select('ownership_type', DB::raw('COUNT(*) as total'))
            ->groupBy('ownership_type')
            ->pluck('total', 'ownership_type')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function equipmentTypeDistribution(): array
    {
        return Equipment::query()
            ->join('equipment_types', 'equipment_types.id', '=', 'equipments.equipment_type_id')
            ->where('equipments.active', true)
            ->visibleInDashboard()
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('equipments.project_id', (int) $projectId))
            ->select('equipments.ownership_type', 'equipment_types.name', DB::raw('COUNT(*) as total'))
            ->groupBy('equipments.ownership_type', 'equipment_types.name')
            ->orderBy('equipments.ownership_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'ownership' => $row->ownership_type,
                'type' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function periodTotals(string $from, string $to): array
    {
        $row = $this->statsBase($from, $to)
            ->select(
                DB::raw('SUM(equipment_daily_stats.worked_hours) as engine_hours'),
                DB::raw('SUM(equipment_daily_stats.distance_km) as mileage')
            )
            ->first();

        return [
            'engine_hours' => round((float) ($row->engine_hours ?? 0), 1),
            'mileage' => round((float) ($row->mileage ?? 0), 1),
        ];
    }

    private function periodAverages(string $from, string $to): array
    {
        $engine = $this->statsBase($from, $to)
            ->whereIn(DB::raw('LOWER(TRIM(equipment_types.name))'), self::ENGINE_HOURS_TYPES)
            ->select(
                DB::raw('SUM(equipment_daily_stats.worked_hours) as total_hours'),
                DB::raw('COUNT(DISTINCT equipment_daily_stats.equipment_id) as equipment_count')
            )
            ->first();

        $mileage = $this->statsBase($from, $to)
            ->where(DB::raw('LOWER(TRIM(equipment_types.name))'), 'dump truck')
            ->select(
                DB::raw('SUM(equipment_daily_stats.distance_km) as total_mileage'),
                DB::raw('COUNT(DISTINCT equipment_daily_stats.equipment_id) as equipment_count')
            )
            ->first();

        $engineCount = max(0, (int) ($engine->equipment_count ?? 0));
        $mileageCount = max(0, (int) ($mileage->equipment_count ?? 0));

        return [
            'engine_hours' => $engineCount > 0 ? round((float) $engine->total_hours / $engineCount, 1) : 0.0,
            'engine_hours_equipment_count' => $engineCount,
            'mileage' => $mileageCount > 0 ? round((float) $mileage->total_mileage / $mileageCount, 1) : 0.0,
            'mileage_equipment_count' => $mileageCount,
        ];
    }

    private function workHourCategories(string $from, string $to): array
    {
        $rows = $this->statsBase($from, $to)
            ->select(
                'equipment_daily_stats.equipment_id',
                'equipment_daily_stats.ownership_type',
                DB::raw('SUM(equipment_daily_stats.worked_hours) as hours'),
                DB::raw('COUNT(DISTINCT equipment_daily_stats.stat_date) as stat_days')
            )
            ->groupBy('equipment_daily_stats.equipment_id', 'equipment_daily_stats.ownership_type')
            ->get();

        $result = [
            Equipment::OWNERSHIP_NWC => $this->emptyCategories(),
            Equipment::OWNERSHIP_ICARE => $this->emptyCategories(),
        ];

        foreach ($rows as $row) {
            $ownership = $row->ownership_type;

            if (! isset($result[$ownership]) || (int) $row->stat_days <= 0) {
                continue;
            }

            $hours = (float) $row->hours / (int) $row->stat_days;

            if ($hours < 1) {
                $result[$ownership]['less_than_1']++;
            } elseif ($hours < 7) {
                $result[$ownership]['from_1_to_7']++;
            } elseif ($hours <= 10) {
                $result[$ownership]['from_7_to_10']++;
            } else {
                $result[$ownership]['overtime']++;
            }
        }

        return $result;
    }

    private function emptyCategories(): array
    {
        return [
            'less_than_1' => 0,
            'from_1_to_7' => 0,
            'from_7_to_10' => 0,
            'overtime' => 0,
        ];
    }

    private function topEquipment(string $from, string $to, string $direction): array
    {
        return $this->statsBase($from, $to)
            ->select(
                'equipments.name',
                'equipment_daily_stats.ownership_type',
                'equipment_types.name as type_name',
                DB::raw('SUM(equipment_daily_stats.worked_hours) as hours')
            )
            ->groupBy('equipments.id', 'equipments.name', 'equipment_daily_stats.ownership_type', 'equipment_types.name')
            ->orderBy('hours', $direction)
            ->limit(10)
            ->get()
            ->values()
            ->map(fn ($row, int $index): array => [
                $index + 1,
                $row->name,
                $row->ownership_type,
                $row->type_name,
                round((float) $row->hours, 1),
            ])
            ->all();
    }

    private function projectStats(string $from, string $to): array
    {
        return $this->statsBase($from, $to)
            ->select(
                'projects.name as project_name',
                DB::raw("SUM(CASE WHEN equipment_daily_stats.ownership_type = 'NWC' THEN 1 ELSE 0 END) as nwc_rows"),
                DB::raw("SUM(CASE WHEN equipment_daily_stats.ownership_type = 'ICARE' THEN 1 ELSE 0 END) as icare_rows"),
                DB::raw('SUM(equipment_daily_stats.worked_hours) as hours'),
                DB::raw('SUM(equipment_daily_stats.distance_km) as mileage')
            )
            ->groupBy('projects.id', 'projects.name')
            ->orderBy('projects.name')
            ->get()
            ->map(fn ($row): array => [
                $row->project_name,
                (int) $row->nwc_rows,
                (int) $row->icare_rows,
                round((float) $row->hours, 1),
                round((float) $row->mileage, 1),
            ])
            ->all();
    }

    private function excludedGeneratorCount(): int
    {
        return Equipment::query()
            ->where('active', true)
            ->where('dashboard_exclusion_reason', Equipment::DASHBOARD_EXCLUSION_GENERATOR_GROUP)
            ->when($this->option('project'), fn ($query, $projectId) => $query->where('project_id', (int) $projectId))
            ->count();
    }

    private function processedObjectCount(string $from, string $to): int
    {
        return $this->statsBase($from, $to)
            ->distinct('equipment_daily_stats.equipment_id')
            ->count('equipment_daily_stats.equipment_id');
    }
}
