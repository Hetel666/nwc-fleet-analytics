<?php

namespace App\Services;

use App\Models\ProjectWialonGroup;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

class WialonEfficiencyReportService
{
    private ?array $resolvedSettings = null;

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

        $resourceId = (int) config('fleet.wialon.efficiency_report_resource_id');
        $templateId = (int) config('fleet.wialon.efficiency_report_template_id');
        $templateName = (string) config('fleet.wialon.efficiency_report_template_name');
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

        return $this->resolvedSettings = [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'chunk_size' => max(1, (int) config('fleet.wialon.efficiency_report_chunk_size', 500)),
            'timeout' => max(5, (int) config('fleet.wialon.efficiency_report_timeout', 90)),
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

                $tables = [];

                foreach (($result['reportResult']['tables'] ?? []) as $index => $table) {
                    if (! is_array($table)) {
                        continue;
                    }

                    $rowCount = (int) ($table['rows'] ?? 0);
                    $rows = $this->loadRows($sid, (int) $index, $rowCount, $settings['chunk_size']);

                    if (count($rows) !== $rowCount) {
                        throw new RuntimeException("Wialon efficiency report table {$index} returned ".count($rows)." of {$rowCount} rows.");
                    }

                    $tables[] = ['index' => (int) $index, 'table' => $table, 'rows' => $rows];
                }

                if (! collect($tables)->contains(fn (array $item): bool => in_array('duration', $item['table']['header_type'] ?? [], true))) {
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
