<?php

namespace App\Console\Commands;

use App\Services\EngineHoursTop20SyncService;
use Illuminate\Console\Command;

class DiagnoseEngineHoursTop20 extends Command
{
    protected $signature = 'fleet:diagnose-engine-hours-top20
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--date= : Single date in YYYY-MM-DD format}
        {--project= : Project database ID}
        {--ownership= : NWC or ICARE}
        {--unit= : Wialon ID or unit name}
        {--details : Show Top rows}';

    protected $description = 'Diagnose Top 20 engine hours unit-day rows.';

    public function handle(EngineHoursTop20SyncService $sync): int
    {
        $result = $sync->diagnose([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'date' => $this->option('date'),
            'project_id' => $this->option('project'),
            'ownership_type' => $this->option('ownership'),
            'unit' => $this->option('unit'),
        ]);

        $this->table(
            ['Metric', 'Value'],
            collect($result)
                ->except(['top_most', 'top_least'])
                ->map(fn (mixed $value, string $key): array => [
                    $key,
                    is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value,
                ])
                ->all()
        );

        if ($this->option('details')) {
            foreach (['top_most' => 'Top cox', 'top_least' => 'Top az'] as $key => $title) {
                $this->line($title);
                $this->table(
                    ['Date', 'Unit', 'Wialon ID', 'Type', 'Project', 'Ownership', 'Engine hours', 'Source', 'Status'],
                    collect($result[$key])->map(fn ($row): array => [
                        $row->stat_date?->toDateString(),
                        $row->unit_name,
                        $row->wialon_unit_id,
                        $row->vehicle_type,
                        $row->project_name,
                        $row->ownership_type,
                        $row->engine_hours,
                        $row->engine_hours_source,
                        $row->parse_status,
                    ])->all()
                );
            }
        }

        return self::SUCCESS;
    }
}
