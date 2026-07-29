<?php

namespace App\Services;

use App\Models\ProjectWialonGroup;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

class WialonEngineHoursReportService
{
    private ?array $resolvedSettings = null;

    public function __construct(
        private WialonService $wialon,
        private ?WialonReportSessionLock $reportSessionLock = null,
    ) {}

    public function findReportTemplate(?string $name = null): ?array
    {
        $resourceId = (int) config('fleet.wialon.engine_hours_report_resource_id');
        $templateName = $name ?: (string) config('fleet.wialon.engine_hours_report_template_name', 'Engine hours: NWC vs İCARƏ (Api)');

        return $this->wialon->findReportTemplateByName($resourceId > 0 ? $resourceId : null, $templateName);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->resolvedSettings !== null) {
            return $this->resolvedSettings;
        }

        $resourceId = (int) config('fleet.wialon.engine_hours_report_resource_id');
        $templateId = (int) config('fleet.wialon.engine_hours_report_template_id');
        $templateName = (string) config('fleet.wialon.engine_hours_report_template_name', 'Engine hours: NWC vs İCARƏ (Api)');
        $templateType = null;

        if ($resourceId <= 0) {
            throw new RuntimeException('Wialon engine hours report resource id is not configured.');
        }

        $template = $this->findReportTemplate($templateName);

        if ($template !== null) {
            $templateId = (int) $template['id'];
            $resourceId = (int) ($template['resource_id'] ?? $resourceId);
            $templateType = $template['type'] ?? null;
        } elseif ($templateId <= 0) {
            throw new RuntimeException("Wialon report template '{$templateName}' was not found.");
        }

        return $this->resolvedSettings = [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'template_type' => $templateType,
            'interval_flags' => (int) config('fleet.wialon.engine_hours_report_interval_flags', 0),
            'chunk_size' => max(1, (int) config('fleet.wialon.engine_hours_report_chunk_size', 500)),
            'timeout' => max(5, (int) config('fleet.wialon.engine_hours_report_timeout', 30)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executeForGroupWithSession(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
    {
        return ($this->reportSessionLock ?? app(WialonReportSessionLock::class))->run(
            fn (): array => $this->executeForGroupWithSessionUnlocked($group, $from, $to, $sid)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executeForGroupWithSessionUnlocked(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
    {
        $groupId = $group instanceof ProjectWialonGroup ? $group->wialon_group_id : $group;
        $settings = $this->settings();
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
            throw new RuntimeException('Wialon engine hours report returned no response.');
        }

        $response['cleanup_error'] = $cleanupError;

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadReportTables(string $sid, array $result): array
    {
        $tables = [];

        foreach (($result['reportResult']['tables'] ?? []) as $tableIndex => $table) {
            $rows = $this->loadRows($sid, (int) $tableIndex, is_array($table) ? $table : []);

            $tables[] = [
                'index' => (int) $tableIndex,
                'table' => $table,
                'rows' => $rows,
            ];
        }

        return $tables;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(string $sid, int $tableIndex, array $table): array
    {
        $rowCount = (int) ($table['rows'] ?? 0);
        $chunkSize = max(1, (int) config('fleet.wialon.engine_hours_report_chunk_size', 500));
        $rows = [];

        if ($rowCount <= 0) {
            return [];
        }

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
    private function withNestedRows(string $sid, int $tableIndex, array $rows, int $depth = 0): array
    {
        $maxDepth = max(0, (int) config('fleet.wialon.engine_hours_report_nested_depth', 1));

        foreach ($rows as $index => $row) {
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
    private function loadNestedRows(string $sid, int $tableIndex, int $rowIndex): array
    {
        try {
            $rows = $this->wialon->getReportResultSubrows($tableIndex, $rowIndex, $sid);

            if ($rows !== []) {
                return array_values(array_filter($rows, 'is_array'));
            }
        } catch (Throwable) {
            // Fall back to select_result_rows variants used by older Wialon installations.
        }

        $configs = [];

        foreach ([0, 1, 2, 3] as $level) {
            $configs[] = ['type' => 'row', 'data' => ['rows' => [(string) $rowIndex], 'level' => $level, 'unitInfo' => 1]];
            $configs[] = ['type' => 'row', 'data' => ['row' => (string) $rowIndex, 'level' => $level, 'unitInfo' => 1]];
            $configs[] = ['type' => 'row', 'data' => ['rows' => [(string) $rowIndex], 'level' => $level]];
            $configs[] = ['type' => 'row', 'data' => ['row' => (string) $rowIndex, 'level' => $level]];
        }

        foreach ($configs as $config) {
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

    private function cleanup(string $sid): void
    {
        $this->wialon->cleanupReportResult($sid);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowRequestConfigs(int $from, int $to): array
    {
        return [
            ['type' => 'range', 'data' => ['from' => $from, 'to' => $to, 'level' => 1, 'unitInfo' => 1]],
            ['type' => 'range', 'data' => ['from' => $from, 'to' => $to, 'level' => 0, 'unitInfo' => 1]],
            ['type' => 'range', 'data' => ['from' => $from, 'to' => $to, 'unitInfo' => 1]],
        ];
    }
}
