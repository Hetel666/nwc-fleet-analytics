<?php

namespace App\Console\Commands;

use App\Models\GeofenceViolationReportRow;
use App\Models\ProjectWialonGroup;
use App\Services\GeofenceViolationReportImporter;
use App\Services\GeofenceViolationReportParser;
use App\Services\WialonReportSessionLock;
use App\Services\WialonService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SyncGeofenceViolationReport extends Command
{
    protected $signature = 'fleet:sync-geofence-violations-report
        {--from= : Start datetime in Asia/Baku}
        {--to= : End datetime in Asia/Baku}
        {--group= : Wialon group ID}
        {--project= : Project ID or exact project name}
        {--force : Replace stored rows covered by a successful report result}';

    protected $description = 'Fetch and import the Wialon "Geofence Pozuntuları api" report.';

    public function handle(
        WialonService $wialon,
        WialonReportSessionLock $reportLock,
        GeofenceViolationReportParser $parser,
        GeofenceViolationReportImporter $importer
    ): int {
        [$from, $to] = $this->period();
        $groups = $this->groups();

        if ($groups->isEmpty()) {
            $this->warn('No active project Wialon groups found.');

            return self::SUCCESS;
        }

        $settings = $this->settings($wialon);
        $totals = ['source' => 0, 'imported' => 0, 'rejected' => 0, 'skipped' => 0, 'malformed' => 0];
        $failures = 0;

        foreach ($groups as $group) {
            try {
                $report = $reportLock->run(fn (): array => $wialon->getReportTablesRows(
                    $settings['resource_id'],
                    $settings['template_id'],
                    $group->wialon_group_id,
                    $from->timestamp,
                    $to->timestamp,
                    $settings['chunk_size'],
                    $settings['interval_flags'],
                    false,
                    $settings['timeout']
                ));
                $parsed = $parser->parse($report, $group, $from, $to);
                $result = $importer->import($parsed['records'], now(config('app.timezone')));

                if ($result['rejected'] > 0 || $parsed['malformed_rows'] > 0) {
                    throw new RuntimeException(sprintf(
                        'Report rows rejected: %d; malformed: %d. Aggregated or invalid intervals were not imported.',
                        $result['rejected'],
                        $parsed['malformed_rows']
                    ));
                }

                if ((bool) $this->option('force')) {
                    $this->removeStaleRows($group, $from, $to, $parsed['records']);
                }

                $totals['source'] += $parsed['source_rows'];
                $totals['imported'] += $result['imported'];
                $totals['rejected'] += $result['rejected'];
                $totals['skipped'] += $parsed['skipped_types'];
                $totals['malformed'] += $parsed['malformed_rows'];

                $this->line(sprintf(
                    '%s | %s | source=%d imported=%d skipped_types=%d',
                    $group->wialon_group_id,
                    $group->name,
                    $parsed['source_rows'],
                    $result['imported'],
                    $parsed['skipped_types']
                ));
            } catch (Throwable $exception) {
                $failures++;
                $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());
                Log::warning('Geofence violations report synchronization failed', [
                    'group_id' => $group->wialon_group_id,
                    'project_id' => $group->project_id,
                    'from' => $from->toDateTimeString(),
                    'to' => $to->toDateTimeString(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->table(['Metric', 'Value'], [
            ['groups processed', $groups->count() - $failures],
            ['groups failed', $failures],
            ['source rows', $totals['source']],
            ['imported periods', $totals['imported']],
            ['rejected periods', $totals['rejected']],
            ['skipped equipment types', $totals['skipped']],
            ['malformed rows', $totals['malformed']],
        ]);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{resource_id: int, template_id: int, chunk_size: int, interval_flags: int, timeout: int}
     */
    private function settings(WialonService $wialon): array
    {
        $resourceId = (int) config('geofence_violations.resource_id');
        $templateId = (int) config('geofence_violations.template_id');
        $templateName = (string) config('geofence_violations.report_name', GeofenceViolationReportRow::REPORT_NAME);

        if ($resourceId <= 0) {
            throw new RuntimeException('Geofence violations report resource id is not configured.');
        }

        if ($templateId <= 0) {
            $template = $wialon->findReportTemplateByName($resourceId, $templateName);

            if ($template === null) {
                throw new RuntimeException("Wialon report template '{$templateName}' was not found.");
            }

            $templateId = (int) $template['id'];
            $resourceId = (int) ($template['resource_id'] ?? $resourceId);
        }

        return [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'chunk_size' => max(1, (int) config('geofence_violations.chunk_size', 500)),
            'interval_flags' => (int) config('geofence_violations.interval_flags', 0),
            'timeout' => max(5, (int) config('geofence_violations.timeout', 60)),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('app.timezone', 'Asia/Baku');
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), $timezone)
            : CarbonImmutable::now($timezone);
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), $timezone)
            : $to->startOfDay();

        return $from->lte($to) ? [$from, $to] : [$to, $from];
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
                    ctype_digit($project)
                        ? $query->whereKey((int) $project)
                        : $query->where('name', $project);
                });
            })
            ->orderBy('wialon_group_id')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function removeStaleRows(
        ProjectWialonGroup $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $records
    ): void {
        $periodKeys = collect($records)->pluck('period_key')->filter()->values();

        GeofenceViolationReportRow::query()
            ->where('project_id', $group->project_id)
            ->where('ownership_type', $group->ownership_type)
            ->where('exited_at', '<=', $to)
            ->where('last_confirmed_at', '>=', $from)
            ->when($periodKeys->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('period_key', $periodKeys))
            ->delete();
    }
}
