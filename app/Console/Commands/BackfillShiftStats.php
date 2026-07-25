<?php

namespace App\Console\Commands;

use App\Models\ProjectWialonGroup;
use App\Services\FleetShiftDailyStatsSyncService;
use App\Services\WialonShiftReportParser;
use App\Services\WialonShiftReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BackfillShiftStats extends Command
{
    protected $signature = 'fleet:backfill-shift-stats
        {--from= : Start date in YYYY-MM-DD format}
        {--to= : End date in YYYY-MM-DD format}
        {--group= : Wialon group ID}
        {--project= : Project ID or exact project name}
        {--dry-run : Preview without writing records}
        {--force : Actually write records}
        {--details : Show per-unit-day rows}';

    protected $description = 'Backfill shift-based daily stats from Wialon reports. Requires --dry-run unless --force is explicitly used.';

    public function handle(
        WialonShiftReportService $reports,
        WialonShiftReportParser $parser,
        FleetShiftDailyStatsSyncService $sync
    ): int {
        if (! $this->option('dry-run') && ! $this->option('force')) {
            $this->error('Run with --dry-run first. Use --force only after verifying one group/day manually.');

            return self::INVALID;
        }

        [$from, $to] = $this->period();
        $groups = $this->groups();
        $isDryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('force');
        $failed = 0;

        foreach ($groups as $group) {
            try {
                $equipmentCount = $sync->equipmentForGroup($group)->count();

                if ($equipmentCount === 0) {
                    $this->line(sprintf(
                        '%s | %s | equipment=0 parsed=0 unknown=0 skipped=no eligible equipment',
                        $group->wialon_group_id,
                        $group->name
                    ));

                    continue;
                }

                $report = $reports->executeForGroup($group, $from, $to);
                $parsed = $parser->parse($report);

                if ($isDryRun) {
                    $this->line(sprintf(
                        '[dry-run] %s | %s | equipment=%d parsed=%d unknown=%d',
                        $group->wialon_group_id,
                        $group->name,
                        $equipmentCount,
                        count($parsed['records']),
                        $parsed['unknown_rows']
                    ));
                    continue;
                }

                $result = $sync->syncGroup($group, $from, $to, $parsed['records'], [
                    'resource_id' => $report['resource_id'],
                    'template_id' => $report['template_id'],
                ], null, (bool) $this->option('details'));

                $this->line(sprintf(
                    '%s | %s | unit-days=%d saved=%d updated=%d unknown=%d',
                    $group->wialon_group_id,
                    $group->name,
                    $result['unit_days'],
                    $result['saved_records'],
                    $result['updated_records'],
                    $result['unknown_rows']
                ));
            } catch (Throwable $exception) {
                $failed++;
                $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('fleet_efficiency.timezone', 'Asia/Baku');
        $from = CarbonImmutable::parse((string) ($this->option('from') ?: now($timezone)->subDay()->toDateString()), $timezone)->startOfDay();
        $to = CarbonImmutable::parse((string) ($this->option('to') ?: $from->toDateString()), $timezone)->endOfDay();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function groups()
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when($this->option('group'), fn (Builder $query, string $groupId) => $query->where('wialon_group_id', trim($groupId)))
            ->when($this->option('project'), function (Builder $query, string $project): void {
                $project = trim($project);
                $query->whereHas('project', function (Builder $query) use ($project): void {
                    if (ctype_digit($project)) {
                        $query->whereKey((int) $project);
                    } else {
                        $query->where('name', $project);
                    }
                });
            })
            ->orderBy('wialon_group_id')
            ->get();
    }
}
