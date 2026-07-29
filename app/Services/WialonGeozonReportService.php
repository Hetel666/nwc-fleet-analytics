<?php

namespace App\Services;

use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

class WialonGeozonReportService
{
    public function __construct(
        private WialonService $wialon,
        private ?WialonReportSessionLock $reportSessionLock = null,
    ) {}

    public function findTemplateByName(?string $name = null): ?array
    {
        $resourceId = (int) config('fleet.wialon.geozon_report_resource_id');
        $templateName = $name ?: (string) config('fleet.wialon.geozon_report_template_name', 'geozon api');

        return $this->wialon->findReportTemplateByName($resourceId > 0 ? $resourceId : null, $templateName);
    }

    /**
     * @return array<string, mixed>
     */
    public function executeForGroup(int|string $groupId, CarbonInterface $from, CarbonInterface $to): array
    {
        return ($this->reportSessionLock ?? app(WialonReportSessionLock::class))->run(
            fn (): array => $this->executeForGroupUnlocked($groupId, $from, $to)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executeForGroupUnlocked(int|string $groupId, CarbonInterface $from, CarbonInterface $to): array
    {
        $settings = $this->settings();
        $sid = $this->wialon->getSessionId();
        $cleanupError = null;
        $response = null;

        try {
            $this->wialon->cleanupReportResult($sid);

            $result = $this->wialon->executeReport(
                $settings['resource_id'],
                $settings['template_id'],
                $groupId,
                $from->timestamp,
                $to->timestamp,
                $settings['interval_flags'],
                $sid,
                false,
                max(5, (int) config('fleet.wialon.geozon_report_timeout', 30))
            );

            $tables = $result['reportResult']['tables'] ?? [];

            if ($tables === []) {
                $response = [
                    'resource_id' => $settings['resource_id'],
                    'template_id' => $settings['template_id'],
                    'template_name' => $settings['template_name'],
                    'template_type' => $settings['template_type'],
                    'object_id' => (string) $groupId,
                    'from' => $from,
                    'to' => $to,
                    'result' => $result,
                    'table_index' => null,
                    'table_name' => '',
                    'table' => ['header' => [], 'rows' => 0],
                    'rows' => [],
                ];

                return $response;
            }

            $tableIndex = $this->geofenceTableIndex($tables);

            if ($tableIndex === null) {
                throw new RuntimeException('Geozon report table was not found in Wialon result.');
            }

            $table = $tables[$tableIndex] ?? [];
            $rows = $this->getTopLevelRows($sid, $tableIndex, $table);
            $rows = $this->withNestedRows($sid, $tableIndex, $rows);

            $response = [
                'resource_id' => $settings['resource_id'],
                'template_id' => $settings['template_id'],
                'template_name' => $settings['template_name'],
                'template_type' => $settings['template_type'],
                'object_id' => (string) $groupId,
                'from' => $from,
                'to' => $to,
                'result' => $result,
                'table_index' => $tableIndex,
                'table_name' => (string) (($table['name'] ?? null) ?: ($table['label'] ?? '')),
                'table' => $table,
                'rows' => $rows,
            ];
        } finally {
            try {
                $this->wialon->cleanupReportResult($sid);
            } catch (Throwable $exception) {
                $cleanupError = $exception->getMessage();
            }
        }

        if ($response === null) {
            throw new RuntimeException('Wialon geozon report returned no response.');
        }

        $response['cleanup_error'] = $cleanupError;

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $resourceId = (int) config('fleet.wialon.geozon_report_resource_id');
        $templateId = (int) config('fleet.wialon.geozon_report_template_id');
        $templateName = (string) config('fleet.wialon.geozon_report_template_name', 'geozon api');
        $templateType = null;

        if ($resourceId <= 0) {
            throw new RuntimeException('Wialon geozon report resource id is not configured.');
        }

        if ($templateId <= 0) {
            $template = $this->findTemplateByName($templateName);

            if ($template === null) {
                throw new RuntimeException("Wialon report template '{$templateName}' was not found.");
            }

            $templateId = (int) $template['id'];
            $resourceId = (int) ($template['resource_id'] ?? $resourceId);
            $templateType = $template['type'] ?? null;
        }

        return [
            'resource_id' => $resourceId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'template_type' => $templateType,
            'interval_flags' => (int) config('fleet.wialon.geozon_report_interval_flags', 0),
            'chunk_size' => max(1, (int) config('fleet.wialon.geozon_report_chunk_size', 500)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tables
     */
    public function geofenceTableIndex(array $tables): ?int
    {
        foreach ($tables as $index => $table) {
            $text = mb_strtolower(trim(implode(' ', array_filter([
                $table['name'] ?? '',
                $table['label'] ?? '',
                ...($table['header'] ?? []),
            ]))));

            if (
                str_contains($text, 'geofence')
                || str_contains($text, 'geozon')
                || str_contains($text, 'geozone')
                || str_contains($text, 'zone')
                || str_contains($text, 'геозон')
            ) {
                return (int) $index;
            }
        }

        return array_key_first($tables);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopLevelRows(string $sid, int $tableIndex, array $table): array
    {
        $rowCount = (int) ($table['rows'] ?? 0);

        if ($rowCount <= 0) {
            return [];
        }

        $chunkSize = max(1, (int) config('fleet.wialon.geozon_report_chunk_size', 500));
        $rows = [];

        for ($from = 0; $from < $rowCount; $from += $chunkSize) {
            $to = min($rowCount - 1, $from + $chunkSize - 1);
            $chunk = [];

            try {
                $chunk = $this->wialon->selectReportResultRows($tableIndex, [
                    'type' => 'range',
                    'data' => [
                        'from' => $from,
                        'to' => $to,
                        'level' => 0,
                        'unitInfo' => 1,
                    ],
                ], $sid);
            } catch (Throwable) {
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function withNestedRows(string $sid, int $tableIndex, array $rows): array
    {
        foreach ($rows as $index => $row) {
            if ($this->rowChildren($row) !== []) {
                continue;
            }

            $children = $this->getNestedRows($sid, $tableIndex, $index);

            if ($children !== []) {
                $rows[$index]['r'] = $children;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getNestedRows(string $sid, int $tableIndex, int $rowIndex): array
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
