<?php

namespace App\Services;

use App\Models\ProjectWialonGroup;
use Carbon\CarbonInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

class WialonNightDayEfficiencyReportService
{
    private ?array $resolvedSettings = null;

    private ?array $sourceTemplate = null;

    public function __construct(
        private WialonService $wialon,
        private WialonReportSessionLock $reportSessionLock,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        if ($this->resolvedSettings !== null) {
            return $this->resolvedSettings;
        }

        $resourceId = (int) config('fleet.wialon.night_day_efficiency_report_resource_id');
        $templateId = (int) config('fleet.wialon.night_day_efficiency_report_template_id');
        $templateName = (string) config('fleet.wialon.night_day_efficiency_report_template_name');
        $template = $this->wialon->findReportTemplateByName($resourceId ?: null, $templateName);

        if ($template !== null) {
            $resourceId = (int) $template['resource_id'];
            $templateId = (int) $template['id'];
        }

        if ($resourceId <= 0 || $templateId <= 0 || $template === null) {
            throw new RuntimeException("Wialon night day efficiency report '{$templateName}' was not found.");
        }

        if (($template['type'] ?? null) !== 'avl_unit_group') {
            throw new RuntimeException("Wialon night day efficiency report '{$templateName}' is not bound to unit groups.");
        }

        return $this->resolvedSettings = [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'chunk_size' => max(1, (int) config('fleet.wialon.night_day_efficiency_report_chunk_size', 500)),
            'timeout' => max(5, (int) config('fleet.wialon.night_day_efficiency_report_timeout', 90)),
        ];
    }

    /** @return array<string, mixed> */
    public function execute(ProjectWialonGroup $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
    {
        return $this->reportSessionLock->run(function () use ($group, $from, $to, $sid): array {
            $settings = $this->settings();
            $response = null;

            try {
                $this->wialon->cleanupReportResult($sid);
                $result = $this->wialon->executeReportTemplate(
                    $settings['resource_id'],
                    $this->apiTemplate($settings, $from, $sid),
                    $group->wialon_group_id,
                    $from->timestamp,
                    $to->timestamp,
                    0,
                    $sid,
                    false,
                    $settings['timeout'],
                );
                $reportResult = $result['reportResult'] ?? null;

                if (! is_array($reportResult) || ! is_array($reportResult['tables'] ?? null)) {
                    throw new RuntimeException('Wialon night day efficiency report returned an invalid result structure.');
                }

                $tables = [];

                foreach ($reportResult['tables'] as $index => $table) {
                    if (! is_array($table)) {
                        continue;
                    }

                    $rowCount = (int) ($table['rows'] ?? 0);
                    $rows = $this->loadRows($sid, (int) $index, $rowCount, $settings['chunk_size']);

                    if (count($rows) !== $rowCount) {
                        throw new RuntimeException("Wialon night day efficiency table {$index} returned ".count($rows)." of {$rowCount} rows.");
                    }

                    $tables[] = ['index' => (int) $index, 'table' => $table, 'rows' => $rows];
                }

                if ($reportResult['tables'] !== []
                    && ! collect($tables)->contains(fn (array $item): bool => in_array('duration', $item['table']['header_type'] ?? [], true))) {
                    throw new RuntimeException('Wialon night day efficiency report did not return the Engine hours table.');
                }

                $response = [
                    'resource_id' => $settings['resource_id'],
                    'template_id' => $settings['template_id'],
                    'template_name' => $settings['template_name'],
                    'object_id' => (string) $group->wialon_group_id,
                    'from' => $from,
                    'to' => $to,
                    'result' => $result,
                    'tables' => $tables,
                ];
            } finally {
                try {
                    $this->wialon->cleanupReportResult($sid);
                } catch (Throwable) {
                    // Cleanup must not change a successfully loaded report into a failed task.
                }
            }

            return $response ?? throw new RuntimeException('Wialon night day efficiency report returned no response.');
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function apiTemplate(array $settings, CarbonInterface $date, string $sid): array
    {
        $template = $this->sourceTemplate ??= $this->wialon->getReportTemplateData(
            $settings['resource_id'],
            $settings['template_id'],
            $sid,
        );
        $timezone = (string) config('historical_recalculation.timezone', 'Asia/Baku');
        $offsetMinutes = intdiv((new DateTimeZone($timezone))->getOffset($date), 60);
        $engineTableFound = false;

        foreach ($template['tbl'] ?? [] as $index => $table) {
            if (($table['n'] ?? null) !== 'unit_group_engine_hours') {
                continue;
            }

            $engineTableFound = true;
            $schedule = $table['sch'] ?? [];

            if ((int) ($schedule['fl'] ?? 0) !== 1 || $this->scheduleWindows($schedule) !== ['0-479', '1080-1439']) {
                throw new RuntimeException('Wialon night day efficiency table must be limited to 00:00-07:59 and 18:00-23:59 Asia/Baku.');
            }

            if ($offsetMinutes !== 240) {
                throw new RuntimeException('Wialon night day efficiency currently requires the Asia/Baku UTC+04:00 offset.');
            }

            // Wialon applies table schedules in UTC. For one Baku calendar day
            // we send the equivalent API windows: 20:00-03:59 and 14:00-23:59 UTC.
            $template['tbl'][$index]['sch']['f1'] = 0;
            $template['tbl'][$index]['sch']['t1'] = 239;
            $template['tbl'][$index]['sch']['f2'] = 840;
            $template['tbl'][$index]['sch']['t2'] = 1439;
        }

        if (! $engineTableFound) {
            throw new RuntimeException('Wialon night day efficiency template has no Engine hours table.');
        }

        return $template;
    }

    /** @return array<int, string> */
    private function scheduleWindows(array $schedule): array
    {
        $windows = [];

        foreach ([['f1', 't1'], ['f2', 't2']] as [$fromKey, $toKey]) {
            $from = (int) ($schedule[$fromKey] ?? -1);
            $to = (int) ($schedule[$toKey] ?? -1);

            if ($from >= 0 && $to >= 0 && $from <= $to) {
                $windows[] = "{$from}-{$to}";
            }
        }

        sort($windows);

        return $windows;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRows(string $sid, int $tableIndex, int $rowCount, int $chunkSize): array
    {
        $rows = [];

        for ($from = 0; $from < $rowCount; $from += $chunkSize) {
            $to = min($rowCount - 1, $from + $chunkSize - 1);

            try {
                $chunk = $this->wialon->selectReportResultRows($tableIndex, [
                    'type' => 'range',
                    'data' => ['from' => $from, 'to' => $to, 'level' => 1, 'unitInfo' => 1],
                ], $sid);
            } catch (Throwable) {
                $chunk = [];
            }

            if ($chunk === []) {
                $chunk = $this->wialon->getReportResultRows($tableIndex, $from, $to, $sid);
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
}
