<?php

namespace App\Services;

use App\Models\ProjectWialonGroup;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

class WialonEfficiencyReportService
{
    /** @var array<string, array<string, mixed>> */
    private array $resolvedSettings = [];

    public function __construct(
        private WialonService $wialon,
        private WialonReportSessionLock $reportSessionLock,
    ) {}

    /** @return array<string, mixed> */
    public function settings(?string $templateName = null): array
    {
        $templateName = trim((string) ($templateName ?: config('fleet.wialon.efficiency_report_template_name')));
        $cacheKey = $templateName;

        if (isset($this->resolvedSettings[$cacheKey])) {
            return $this->resolvedSettings[$cacheKey];
        }

        $resourceId = (int) config('fleet.wialon.efficiency_report_resource_id');
        $templateId = (int) config('fleet.wialon.efficiency_report_template_id');
        $template = $this->wialon->findReportTemplateByName($resourceId ?: null, $templateName);

        if ($template !== null) {
            $resourceId = (int) $template['resource_id'];
            $templateId = (int) $template['id'];
        }

        if ($resourceId <= 0 || $templateId <= 0 || $template === null) {
            throw new RuntimeException("Wialon efficiency report '{$templateName}' was not found.");
        }

        if (($template['type'] ?? null) !== 'avl_unit_group') {
            throw new RuntimeException("Wialon efficiency report '{$templateName}' is not bound to unit groups.");
        }

        return $this->resolvedSettings[$cacheKey] = [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'chunk_size' => max(1, (int) config('fleet.wialon.efficiency_report_chunk_size', 500)),
            'timeout' => max(5, (int) config('fleet.wialon.efficiency_report_timeout', 90)),
        ];
    }

    /** @return array<string, mixed> */
    public function execute(ProjectWialonGroup $group, CarbonInterface $from, CarbonInterface $to, string $sid, ?array $settings = null): array
    {
        return $this->reportSessionLock->run(function () use ($group, $from, $to, $sid, $settings): array {
            $settings ??= $this->settings();
            $response = null;

            try {
                $this->wialon->cleanupReportResult($sid);
                $result = $this->wialon->executeReport(
                    $settings['resource_id'],
                    $settings['template_id'],
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
                    throw new RuntimeException('Wialon efficiency report returned an invalid result structure.');
                }

                $tables = [];

                foreach ($reportResult['tables'] as $index => $table) {
                    if (! is_array($table)) {
                        continue;
                    }

                    $rowCount = (int) ($table['rows'] ?? 0);
                    $rows = $this->loadRows($sid, (int) $index, $rowCount, $settings['chunk_size'], $table);

                    if (count($rows) !== $rowCount) {
                        throw new RuntimeException("Wialon efficiency report table {$index} returned ".count($rows)." of {$rowCount} rows.");
                    }

                    $tables[] = ['index' => (int) $index, 'table' => $table, 'rows' => $rows];
                }

                if ($reportResult['tables'] !== []
                    && ! collect($tables)->contains(fn (array $item): bool => in_array('duration', $item['table']['header_type'] ?? [], true))) {
                    throw new RuntimeException('Wialon efficiency report did not return the Engine hours table.');
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
                    // Cleanup cannot turn a complete report into a failed task.
                }
            }

            return $response ?? throw new RuntimeException('Wialon efficiency report returned no response.');
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRows(string $sid, int $tableIndex, int $rowCount, int $chunkSize, array $table): array
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

        if ($this->hasLocationColumns($table)) {
            $nestedRows = $this->loadRowsWithNestedDates($sid, $tableIndex, $rowCount, $chunkSize);

            if ($this->hasNestedRows($nestedRows)) {
                return $nestedRows;
            }
        }

        return $rows;
    }

    private function hasLocationColumns(array $table): bool
    {
        $headers = collect($table['header'] ?? [])
            ->map(fn (mixed $header): string => mb_strtolower(trim((string) $header)))
            ->all();

        return collect($headers)->contains(fn (string $header): bool => str_contains($header, 'location')
            || str_contains($header, 'положение')
            || str_contains($header, 'polozhenie'));
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRowsWithNestedDates(string $sid, int $tableIndex, int $rowCount, int $chunkSize): array
    {
        if ($rowCount <= 0) {
            return [];
        }

        $rows = [];

        for ($from = 0; $from < $rowCount; $from += $chunkSize) {
            $to = min($rowCount - 1, $from + $chunkSize - 1);

            try {
                $chunk = $this->wialon->selectReportResultRows($tableIndex, [
                    'type' => 'range',
                    'data' => ['from' => $from, 'to' => $to, 'level' => 0, 'unitInfo' => 1],
                ], $sid);
            } catch (Throwable) {
                return [];
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        foreach ($rows as $index => $row) {
            if ($this->rowChildren($row) !== []) {
                continue;
            }

            $children = $this->nestedRows($sid, $tableIndex, $index);

            if ($children !== []) {
                $rows[$index]['r'] = $children;
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function nestedRows(string $sid, int $tableIndex, int $rowIndex): array
    {
        try {
            $subrows = $this->wialon->getReportResultSubrows($tableIndex, $rowIndex, $sid);

            if ($subrows !== []) {
                return array_values(array_filter($subrows, 'is_array'));
            }
        } catch (Throwable) {
            // Some Wialon installations expose nested rows only through select_result_rows.
        }

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

    /** @param array<int, array<string, mixed>> $rows */
    private function hasNestedRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($this->rowChildren($row) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> */
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
