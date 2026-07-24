<?php

namespace App\Console\Commands;

use App\Services\EngineHoursTop20SyncService;
use Illuminate\Console\Command;

class SyncEngineHoursReport extends Command
{
    protected $signature = 'fleet:sync-engine-hours-report
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--date= : Single date in YYYY-MM-DD format}
        {--group= : Wialon group ID}
        {--project= : Project database ID}
        {--unit= : Wialon ID or unit name, for diagnostics only}
        {--ownership= : NWC or ICARE}
        {--force : Re-plan completed items}
        {--limit=10 : Max checkpoint items to process}
        {--details : Show processed item details}';

    protected $description = 'Synchronize Top 20 unit-day engine hours from the Wialon Engine hours report.';

    public function handle(EngineHoursTop20SyncService $sync): int
    {
        $result = $sync->sync([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'date' => $this->option('date'),
            'group' => $this->option('group'),
            'project' => $this->option('project'),
            'unit' => $this->option('unit'),
            'ownership' => $this->option('ownership'),
            'force' => (bool) $this->option('force'),
            'limit' => (int) $this->option('limit'),
            'details' => (bool) $this->option('details'),
        ]);

        $this->line('Planning');
        $this->table(['Metric', 'Value'], collect($result['planned'])->map(fn (mixed $value, string $key): array => [$key, $value])->all());

        $this->line('Run');
        $this->table(['Metric', 'Value'], collect($result['run'])->except('details')->map(fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : $value])->all());

        if ($this->option('details') && ($result['run']['details'] ?? []) !== []) {
            $details = collect($result['run']['details']);

            $this->table(
                ['Date', 'Group', 'Name', 'Rows received', 'Rows saved', 'Null', 'Invalid', 'Excluded types', 'Missing unit'],
                $details->map(fn (array $row): array => [
                    $row['date'] ?? '',
                    $row['group'] ?? '',
                    $row['name'] ?? '',
                    $row['rows_received'] ?? 0,
                    $row['rows_saved'] ?? 0,
                    $row['null_rows'] ?? 0,
                    $row['invalid_rows'] ?? 0,
                    $row['excluded_vehicle_types'] ?? 0,
                    $row['missing_unit'] ?? 0,
                ])->all()
            );

            foreach ($details as $row) {
                if (($row['tables'] ?? []) !== []) {
                    $this->line('Report tables for '.$row['group'].':');
                    $this->table(
                        ['Index', 'Name', 'Rows', 'Parsed', 'Engine column', 'Engine label'],
                        collect($row['tables'])->map(fn (array $table): array => [
                            $table['index'] ?? '',
                            $table['name'] ?? '',
                            $table['rows'] ?? 0,
                            $table['parsed_records'] ?? 0,
                            $table['engine_hours_column_index'] ?? '',
                            $table['engine_hours_column_label'] ?? '',
                        ])->all()
                    );

                    foreach ($row['tables'] as $table) {
                        if (($table['sample_rows'] ?? []) === []) {
                            continue;
                        }

                        $this->line('Sample rows for table '.($table['name'] ?? $table['index'] ?? '').':');
                        foreach ($table['sample_rows'] as $sample) {
                            $this->line(json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        }
                    }
                }

                if (($row['missing_samples'] ?? []) === []) {
                    continue;
                }

                $this->line('Missing unit samples for '.$row['group'].':');
                $this->table(
                    ['Wialon ID', 'Unit name', 'Engine hours', 'Raw value'],
                    collect($row['missing_samples'])->map(fn (array $sample): array => [
                        $sample['wialon_unit_id'] ?? '',
                        $sample['unit_name'] ?? '',
                        $sample['engine_hours'] ?? '',
                        json_encode($sample['raw_value'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ])->all()
                );
            }
        }

        return (int) ($result['run']['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
