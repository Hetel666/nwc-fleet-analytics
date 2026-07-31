<?php

namespace App\Services;

use App\Models\ProjectWialonGroup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use RuntimeException;
use Throwable;

class WialonShiftReportService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $resolvedSettings = [];

    public function __construct(
        private WialonService $wialon,
        private WialonShiftReportParser $parser,
        private ?WialonReportSessionLock $reportSessionLock = null,
    ) {}

    public function findReportTemplate(?string $name = null): ?array
    {
        $resourceId = (int) config('fleet.wialon.shift_report_resource_id');
        $templateName = $name ?: (string) config('fleet.wialon.shift_report_template_name', 'Qrup report novbe 24 saat (api)');

        return $this->wialon->findReportTemplateByName($resourceId > 0 ? $resourceId : null, $templateName);
    }

    /**
     * @return array<string, mixed>
     */
    public function executeForGroup(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to): array
    {
        $groupId = $group instanceof ProjectWialonGroup ? $group->wialon_group_id : $group;
        $sid = $this->wialon->getSessionId();

        return $this->executeEfficiencyReports((string) $groupId, $from, $to, $sid);
    }

    /**
     * @return array<string, mixed>
     */
    public function executeForGroupWithSession(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
    {
        $groupId = $group instanceof ProjectWialonGroup ? $group->wialon_group_id : $group;

        return $this->executeEfficiencyReports((string) $groupId, $from, $to, $sid);
    }

    /**
     * Execute one configured shift source without loading or combining the
     * other source. The daytime dashboard relies on the Wialon daytime table
     * exactly as returned by its own template.
     *
     * @return array<string, mixed>
     */
    public function executeSourceForGroup(
        string $source,
        ProjectWialonGroup|int|string $group,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        return $this->executeSourceForGroupWithSession(
            $source,
            $group,
            $from,
            $to,
            $this->wialon->getSessionId()
        );
    }

    /** @return array<string, mixed> */
    public function executeSourceForGroupWithSession(
        string $source,
        ProjectWialonGroup|int|string $group,
        CarbonInterface $from,
        CarbonInterface $to,
        string $sid
    ): array {
        $groupId = $group instanceof ProjectWialonGroup ? $group->wialon_group_id : $group;

        return ($this->reportSessionLock ?? app(WialonReportSessionLock::class))->run(
            fn (): array => $this->executePreparedReportUnlocked(
                (string) $groupId,
                $from,
                $to,
                $this->settingsFor($source),
                $sid
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executeEfficiencyReports(string $groupId, CarbonInterface $from, CarbonInterface $to, string $sid): array
    {
        return ($this->reportSessionLock ?? app(WialonReportSessionLock::class))->run(
            function () use ($groupId, $from, $to, $sid): array {
                $reports = [];

                foreach ($this->shiftWindows($from, $to) as $window) {
                    $report = $this->executePreparedReportUnlocked(
                        $groupId,
                        $window['from'],
                        $window['to'],
                        $this->settingsFor('engine_hours'),
                        $sid
                    );
                    $report['source_shift'] = $window['source'];
                    $report['business_date'] = $window['date'];
                    $report['window_name'] = $window['name'];
                    $reports[] = $report;
                }

                return $this->combineReports($reports);
            }
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function executePreparedReportUnlocked(string $groupId, CarbonInterface $from, CarbonInterface $to, array $settings, string $sid): array
    {
        $cleanupError = null;
        $response = null;

        try {
            $this->cleanup($sid);

            $result = $this->wialon->executeReport(
                $settings['resource_id'],
                $settings['template_id'],
                $groupId,
                $from->timestamp,
                $to->timestamp,
                $settings['interval_flags'],
                $sid,
                false,
                $settings['timeout']
            );

            $response = [
                'resource_id' => $settings['resource_id'],
                'template_id' => $settings['template_id'],
                'template_name' => $settings['template_name'],
                'template_type' => $settings['template_type'],
                'object_id' => (string) $groupId,
                'from' => $from,
                'to' => $to,
                'result' => $result,
                'tables' => $this->loadReportTables($sid, $result),
            ];
        } finally {
            try {
                $this->cleanup($sid);
            } catch (Throwable $exception) {
                $cleanupError = $exception->getMessage();
            }
        }

        if ($response === null) {
            throw new RuntimeException('Wialon shift report returned no response.');
        }

        $response['cleanup_error'] = $cleanupError;

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $engineHours = $this->settingsFor('engine_hours');

        return [
            'resource_id' => (string) $engineHours['resource_id'],
            'template_id' => (string) $engineHours['template_id'],
            'template_name' => $engineHours['template_name'],
            'template_type' => $engineHours['template_type'],
            'interval_flags' => $engineHours['interval_flags'],
            'chunk_size' => $engineHours['chunk_size'],
            'timeout' => $engineHours['timeout'],
            'sources' => [
                'engine_hours' => $engineHours,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsFor(string $source): array
    {
        if (! in_array($source, ['engine_hours', 'daytime', 'overtime'], true)) {
            throw new RuntimeException("Unknown Wialon efficiency report source '{$source}'.");
        }

        if (isset($this->resolvedSettings[$source])) {
            return $this->resolvedSettings[$source];
        }

        $prefix = $source === 'engine_hours'
            ? 'fleet.wialon.shift_engine_hours_report_'
            : 'fleet.wialon.shift_'.$source.'_report_';
        $resourceId = (int) config($prefix.'resource_id');
        $templateId = (int) config($prefix.'template_id');
        $templateName = (string) config(
            $prefix.'template_name',
            $source === 'engine_hours'
                ? 'Qrup report Engine hours (api)'
                : ($source === 'daytime' ? 'Qrup report daytime (api)' : 'Qrup report overtime (api)')
        );
        $templateType = null;

        if ($resourceId <= 0) {
            throw new RuntimeException("Wialon {$source} report resource id is not configured.");
        }

        if ($templateId <= 0) {
            $template = $this->wialon->findReportTemplateByName($resourceId, $templateName);

            if ($template === null) {
                throw new RuntimeException("Wialon report template '{$templateName}' was not found.");
            }

            $templateId = (int) $template['id'];
            $resourceId = (int) ($template['resource_id'] ?? $resourceId);
            $templateType = $template['type'] ?? null;
        }

        return $this->resolvedSettings[$source] = [
            'source' => $source,
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'template_type' => $templateType,
            'interval_flags' => (int) config('fleet.wialon.shift_report_interval_flags', 0),
            'chunk_size' => max(1, (int) config('fleet.wialon.shift_report_chunk_size', 500)),
            'timeout' => max(5, (int) config('fleet.wialon.shift_report_timeout', 30)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $reports
     * @return array<string, mixed>
     */
    private function combineReports(array $reports): array
    {
        $tables = [];

        foreach ($reports as $report) {
            $source = (string) ($report['source_shift'] ?? 'daytime');

            foreach ($report['tables'] ?? [] as $table) {
                $table['index'] = count($tables);
                $table['_source_shift'] = $source;
                $table['_business_date'] = $report['business_date'] ?? null;
                $table['_window_name'] = $report['window_name'] ?? null;
                $tables[] = $table;
            }
        }

        $cleanupErrors = array_values(array_filter(array_map(
            fn (array $report): ?string => $report['cleanup_error'] ?? null,
            $reports
        )));
        $first = $reports[0] ?? [];

        return [
            'resource_id' => (string) ($first['resource_id'] ?? ''),
            'template_id' => (string) ($first['template_id'] ?? ''),
            'template_name' => (string) ($first['template_name'] ?? ''),
            'template_type' => $first['template_type'] ?? null,
            'object_id' => $first['object_id'] ?? null,
            'from' => $reports[0]['from'] ?? null,
            'to' => $reports[array_key_last($reports)]['to'] ?? null,
            'tables' => $tables,
            'sources' => collect($reports)
                ->map(fn (array $report): array => [
                    ...$this->reportMetadata($report),
                    'source_shift' => $report['source_shift'] ?? null,
                    'business_date' => $report['business_date'] ?? null,
                    'window_name' => $report['window_name'] ?? null,
                ])
                ->values()
                ->all(),
            'cleanup_error' => implode(' | ', $cleanupErrors),
        ];
    }

    /**
     * @return array<int, array{source: 'daytime'|'overtime', name: string, date: string, from: CarbonInterface, to: CarbonInterface}>
     */
    private function shiftWindows(CarbonInterface $from, CarbonInterface $to): array
    {
        $timezone = (string) config('fleet_efficiency.timezone', 'Asia/Baku');
        $from = CarbonImmutable::instance($from)->timezone($timezone);
        $to = CarbonImmutable::instance($to)->timezone($timezone);
        $windows = [];

        foreach (CarbonPeriod::create($from->startOfDay(), $to->startOfDay()) as $date) {
            $date = CarbonImmutable::instance($date)->timezone($timezone);
            $businessDate = $date->toDateString();
            $dayStart = $date->setTimeFromTimeString((string) config('fleet_efficiency.day_shift.start', '08:00:00'));
            $dayEnd = $date->setTimeFromTimeString((string) config('fleet_efficiency.day_shift.end', '17:59:59'));

            foreach ([
                ['source' => 'overtime', 'name' => 'overtime_morning', 'from' => $date->startOfDay(), 'to' => $dayStart->subSecond()],
                ['source' => 'daytime', 'name' => 'daytime', 'from' => $dayStart, 'to' => $dayEnd],
                ['source' => 'overtime', 'name' => 'overtime_evening', 'from' => $dayEnd->addSecond(), 'to' => $date->endOfDay()],
            ] as $window) {
                $windowFrom = $window['from']->max($from);
                $windowTo = $window['to']->min($to);

                if ($windowFrom->greaterThan($windowTo)) {
                    continue;
                }

                $windows[] = [
                    'source' => $window['source'],
                    'name' => $window['name'],
                    'date' => $businessDate,
                    'from' => $windowFrom,
                    'to' => $windowTo,
                ];
            }
        }

        return $windows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function reportMetadata(array $report): array
    {
        return [
            'resource_id' => $report['resource_id'],
            'template_id' => $report['template_id'],
            'template_name' => $report['template_name'],
            'template_type' => $report['template_type'],
            'table_count' => count($report['tables'] ?? []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadReportTables(string $sid, array $result): array
    {
        $tables = [];

        foreach (($result['reportResult']['tables'] ?? []) as $tableIndex => $table) {
            $rows = $this->loadRows($sid, (int) $tableIndex, is_array($table) ? $table : []);

            $tables[] = [
                'index' => (int) $tableIndex,
                'name' => (string) (($table['name'] ?? null) ?: ($table['label'] ?? '')),
                'table' => $table,
                'rows' => $rows,
            ];
        }

        return $tables;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadRows(string $sid, int $tableIndex, array $table): array
    {
        $rowCount = (int) ($table['rows'] ?? 0);

        if ($rowCount <= 0) {
            return [];
        }

        $chunkSize = max(1, (int) config('fleet.wialon.shift_report_chunk_size', 500));
        $rows = [];

        for ($from = 0; $from < $rowCount; $from += $chunkSize) {
            $to = min($rowCount - 1, $from + $chunkSize - 1);
            $chunk = [];

            foreach ($this->rowRequestConfigs($from, $to) as $config) {
                try {
                    $chunk = $this->wialon->selectReportResultRows($tableIndex, $config, $sid);

                    if ($chunk !== []) {
                        break;
                    }
                } catch (Throwable) {
                    $chunk = [];
                }
            }

            if ($chunk === []) {
                $chunk = $this->wialon->getReportResultRows($tableIndex, $from, $to, $sid);
            }

            foreach ($chunk as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $row['_row_index'] ??= $from + (int) $index;
                $rows[] = $row;
            }
        }

        return $this->withNestedRows($sid, $tableIndex, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function withNestedRows(string $sid, int $tableIndex, array $rows, int $depth = 0): array
    {
        $maxDepth = max(0, (int) config('fleet.wialon.shift_report_nested_depth', 1));

        foreach ($rows as $index => $row) {
            if ($this->rowChildren($row) !== []) {
                $rows[$index]['r'] = $this->withNestedRows($sid, $tableIndex, $this->rowChildren($row), $depth + 1);

                continue;
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            $rowIndex = (int) ($row['_row_index'] ?? $index);
            $children = $this->loadNestedRows($sid, $tableIndex, $rowIndex);

            if ($children !== []) {
                $rows[$index]['r'] = $this->withNestedRows($sid, $tableIndex, $children, $depth + 1);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadNestedRows(string $sid, int $tableIndex, int $rowIndex): array
    {
        $attempts = [
            ['type' => 'row', 'data' => ['rows' => [(string) $rowIndex], 'level' => 1, 'unitInfo' => 1]],
            ['type' => 'row', 'data' => ['row' => (string) $rowIndex, 'level' => 1, 'unitInfo' => 1]],
            ['type' => 'row', 'data' => ['rows' => [$rowIndex], 'unitInfo' => 1]],
        ];

        foreach ($attempts as $config) {
            try {
                $rows = $this->wialon->selectReportResultRows($tableIndex, $config, $sid);

                if ($rows !== []) {
                    return array_values(array_filter($rows, 'is_array'));
                }
            } catch (Throwable) {
                continue;
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseShiftRows(array $report): array
    {
        return $this->parser->parse($report)['records'];
    }

    public function cleanup(string $sid): void
    {
        $this->wialon->cleanupReportResult($sid);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowRequestConfigs(int $from, int $to): array
    {
        return [
            ['type' => 'range', 'data' => ['from' => $from, 'to' => $to, 'level' => 0, 'unitInfo' => 1]],
            ['type' => 'range', 'data' => ['from' => $from, 'to' => $to, 'level' => 1, 'unitInfo' => 1]],
            ['type' => 'range', 'data' => ['from' => $from, 'to' => $to, 'unitInfo' => 1]],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowChildren(array $row): array
    {
        foreach (['r', 'rows', 'children'] as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return array_values(array_filter($row[$key], 'is_array'));
            }
        }

        return [];
    }
}
