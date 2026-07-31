<?php

namespace App\Console\Commands;

use App\Models\ProjectWialonGroup;
use App\Services\WialonShiftReportParser;
use App\Services\WialonShiftReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseShiftReport extends Command
{
    protected $signature = 'fleet:diagnose-shift-report
        {--group= : Wialon group ID}
        {--unit= : Limit parsed details to Wialon unit ID or unit name}
        {--date= : Single date in YYYY-MM-DD format}
        {--from= : Start date/datetime in Asia/Baku}
        {--to= : End date/datetime in Asia/Baku}
        {--details : Show parsed unit-day rows}
        {--raw : Show safe shortened report structure without SID or token}';

    protected $description = 'Diagnose Wialon shift report structure for the efficiency widgets.';

    public function handle(WialonShiftReportService $reports, WialonShiftReportParser $parser): int
    {
        [$from, $to] = $this->period();
        $group = $this->group();

        if (! $group) {
            $this->warn('No active project Wialon group found.');

            return self::SUCCESS;
        }

        try {
            $settings = $reports->settings();
            $report = $reports->executeForGroup($group, $from, $to);
            $parsed = $parser->parse($report);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['report resource ID', $report['resource_id']],
                    ['report template ID', $report['template_id']],
                    ['report template name', $report['template_name']],
                    ['daytime template', $settings['sources']['daytime']['template_name'].' #'.$settings['sources']['daytime']['template_id']],
                    ['overtime template', $settings['sources']['overtime']['template_name'].' #'.$settings['sources']['overtime']['template_id']],
                    ['report template type', $report['template_type'] ?? 'unknown'],
                    ['group ID', $group->wialon_group_id],
                    ['group name', $group->name],
                    ['project', $group->project?->name ?? ''],
                    ['ownership', $group->ownership_type],
                    ['period', $from->toDateTimeString().' - '.$to->toDateTimeString()],
                    ['tables', count($parsed['tables'])],
                    ['parsed unit-day rows', count($parsed['records'])],
                    ['unknown rows', $parsed['unknown_rows']],
                    ['cleanup error', $report['cleanup_error'] ?? ''],
                ]
            );

            $this->table(
                ['Index', 'Name', 'Rows', 'Parsed', 'Unknown', 'Columns'],
                collect($parsed['tables'])->map(fn (array $table): array => [
                    $table['index'],
                    $table['name'],
                    $table['rows'],
                    $table['parsed_records'],
                    $table['unknown_rows'],
                    implode(' | ', $parsed['columns'][$table['index']] ?? []),
                ])->all()
            );

            if ($this->option('details')) {
                $this->printDetails($parsed['records']);
            }

            if ($this->option('raw')) {
                $this->newLine();
                $this->line(json_encode($parsed['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (Throwable $exception) {
            $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());

            return self::FAILURE;
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

    private function group(): ?ProjectWialonGroup
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when($this->option('group'), fn (Builder $query, string $groupId) => $query->where('wialon_group_id', trim($groupId)))
            ->orderBy('wialon_group_id')
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function printDetails(array $records): void
    {
        $unit = mb_strtolower(trim((string) $this->option('unit')));
        $rows = collect($records)
            ->filter(function (array $record) use ($unit): bool {
                if ($unit === '') {
                    return true;
                }

                return str_contains(mb_strtolower((string) ($record['unit_name'] ?? '')), $unit)
                    || str_contains(mb_strtolower((string) ($record['wialon_unit_id'] ?? '')), $unit);
            })
            ->map(fn (array $record): array => [
                $record['statistic_date'] ?? '',
                $record['unit_name'] ?? '',
                $record['wialon_unit_id'] ?? '',
                $record['daytime_hours'] ?? 'NULL',
                $record['overtime_hours'] ?? 'NULL',
                $record['total_hours'] ?? 'NULL',
                $record['reason'] ?? '',
            ])
            ->all();

        $this->table(['Date', 'Unit', 'Wialon ID', 'Daytime', 'Overtime', 'Total', 'Reason'], $rows);
    }
}
