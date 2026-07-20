<?php

namespace App\Console\Commands;

use App\Models\ProjectWialonGroup;
use App\Services\GeofenceReportViolationCalculator;
use App\Services\WialonGeozonReportParser;
use App\Services\WialonGeozonReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseGeozonApi extends Command
{
    protected $signature = 'fleet:diagnose-geozon-api
        {--group= : Wialon group ID}
        {--unit= : Limit details to a Wialon unit ID or unit name}
        {--from= : Start datetime in Asia/Baku}
        {--to= : End datetime in Asia/Baku}
        {--details : Show per-row decisions}
        {--raw : Show safe report metadata and one parent/child row sample}';

    protected $description = 'Diagnose Wialon "geozon api" report parsing and foreign geofence violation calculation.';

    public function handle(
        WialonGeozonReportService $reports,
        WialonGeozonReportParser $parser,
        GeofenceReportViolationCalculator $calculator
    ): int {
        [$from, $to] = $this->period();
        $groups = $this->groups();
        $settings = $reports->settings();
        $template = $reports->findTemplateByName($settings['template_name']);
        $totals = $this->emptyTotals();
        $details = [];
        $raw = null;
        $failed = 0;

        foreach ($groups as $group) {
            try {
                $report = $reports->executeForGroup($group->wialon_group_id, $from, $to);
                $parsed = $parser->parse($report);
                $context = [
                    'resource_id' => $report['resource_id'],
                    'template_id' => $report['template_id'],
                    'table_name' => $report['table_name'],
                    'from' => $from,
                    'to' => $to,
                ];
                $result = $calculator->processGroupReport(
                    $group,
                    $parsed['records'],
                    $context,
                    $this->option('unit') ? trim((string) $this->option('unit')) : null,
                    false
                );
                $result['parent_rows'] = $parsed['parent_rows'];
                $result['nested_rows'] = $parsed['nested_rows'];
                $this->addTotals($totals, $result);
                $details = array_merge($details, $result['details'] ?? []);
                $raw ??= $parsed['raw'];
            } catch (Throwable $exception) {
                $failed++;
                $totals['cleanup_errors'] += str_contains($exception->getMessage(), 'cleanup') ? 1 : 0;
                $this->error($group->wialon_group_id.' | '.$group->name.' | '.$exception->getMessage());
            }
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['report resource ID', $settings['resource_id']],
                ['report template ID', $settings['template_id']],
                ['report template name', $settings['template_name']],
                ['report object type', $template['type'] ?? $settings['template_type'] ?? 'unknown'],
                ['report table name', $raw['table']['name'] ?? $raw['table']['label'] ?? 'unknown'],
                ['groups selected', $groups->count()],
                ['groups processed', $groups->count() - $failed],
                ['groups failed', $failed],
                ['parent rows', $totals['parent_rows']],
                ['nested rows', $totals['nested_rows']],
                ['units found', $totals['nested_rows']],
                ['home visits', $totals['home_visits']],
                ['foreign visits', $totals['foreign_visits']],
                ['foreign visits under 3 hours', $totals['violations_under_threshold']],
                ['foreign visits at least 3 hours', $totals['violations_at_least_threshold']],
                ['unresolved units', $totals['unresolved_units']],
                ['unresolved geofences', $totals['unresolved_geofences']],
                ['ambiguous geofences', $totals['ambiguous_geofences']],
                ['multiple home project conflicts', 0],
                ['project mismatches', $totals['project_mismatches']],
                ['cleanup errors', $totals['cleanup_errors']],
            ]
        );

        if ($this->option('details')) {
            $this->printDetails($details);
        }

        if ($this->option('raw') && $raw !== null) {
            $this->newLine();
            $this->line(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = config('app.timezone');
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), $timezone)
            : CarbonImmutable::now($timezone);
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), $timezone)
            : $to->subHours(max(1, (int) config('fleet.foreign_geofence.geozon_api_sync_lookback_hours', 24)));

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function groups()
    {
        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when($this->option('group'), fn (Builder $query, string $groupId) => $query->where('wialon_group_id', trim($groupId)))
            ->orderBy('wialon_group_id')
            ->get();
    }

    private function printDetails(array $details): void
    {
        $this->table(
            ['Group ID', 'Group name', 'Expected home project', 'Allowed home geofences', 'Parent geofence', 'Unit name', 'Wialon unit ID', 'Reported project', 'Entry time', 'Exit time', 'Duration', 'Home', 'Foreign project', 'Included', 'Reason', 'Match method'],
            collect($details)->map(fn (array $detail): array => [
                $detail['group_id'] ?? '',
                $detail['group_name'] ?? '',
                $detail['expected_home_project'] ?? '',
                implode(', ', $detail['allowed_home_geofences'] ?? []),
                $detail['parent_geofence'] ?? '',
                $detail['unit_name'] ?? '',
                $detail['wialon_unit_id'] ?? '',
                $detail['reported_project'] ?? '',
                $this->formatDate($detail['entry_time'] ?? null),
                $this->formatDate($detail['exit_time'] ?? null),
                $detail['duration_seconds'] ?? '',
                ($detail['is_home_geofence'] ?? false) ? 'yes' : 'no',
                $detail['foreign_project'] ?? '',
                ($detail['included'] ?? false) ? 'yes' : 'no',
                $detail['reason'] ?? '',
                trim(($detail['match_status'] ?? '').' '.($detail['match_method'] ?? '')),
            ])->all()
        );
    }

    private function formatDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : '';
    }

    private function addTotals(array &$totals, array $result): void
    {
        foreach ($totals as $key => $value) {
            $totals[$key] += (int) ($result[$key] ?? 0);
        }
    }

    private function emptyTotals(): array
    {
        return [
            'parent_rows' => 0,
            'nested_rows' => 0,
            'home_visits' => 0,
            'foreign_visits' => 0,
            'violations_under_threshold' => 0,
            'violations_at_least_threshold' => 0,
            'unresolved_units' => 0,
            'unresolved_geofences' => 0,
            'ambiguous_geofences' => 0,
            'project_mismatches' => 0,
            'cleanup_errors' => 0,
        ];
    }
}
