<?php

namespace App\Console\Commands\Concerns;

use App\Services\OperationsDiagnosticService;

trait RendersDiagnosticReports
{
    /**
     * @param  array<string, mixed>  $report
     */
    private function renderDiagnosticReport(array $report, OperationsDiagnosticService $diagnostics): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($this->jsonReport($report, $diagnostics), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $diagnostics->exitCode($report);
        }

        $this->line(str_repeat('=', 60));
        $this->line($report['title']);
        $this->line(str_repeat('=', 60));

        foreach ($report['checks'] as $check) {
            $this->line(sprintf(
                '%-34s %s  %s',
                $check['label'],
                strtoupper($check['status']),
                $check['message'],
            ));
        }

        $this->line(str_repeat('=', 60));
        $this->line($this->summaryLine((string) $report['status']));
        $this->line(str_repeat('=', 60));

        return $diagnostics->exitCode($report);
    }

    private function summaryLine(string $status): string
    {
        return match ($status) {
            OperationsDiagnosticService::OK => 'READY FOR PRODUCTION',
            OperationsDiagnosticService::WARN => 'WARNINGS FOUND',
            OperationsDiagnosticService::FAIL => 'CRITICAL FAILURES FOUND',
            default => strtoupper($status),
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function jsonReport(array $report, OperationsDiagnosticService $diagnostics): array
    {
        return [
            'command' => $this->getName(),
            ...$report,
            'status_label' => $this->jsonStatusLabel((string) $report['status']),
            'timestamp' => $report['generated_at'],
            'summary' => $this->statusSummary($report['checks'] ?? []),
            'exit_code' => $diagnostics->exitCode($report),
        ];
    }

    private function jsonStatusLabel(string $status): string
    {
        return match ($status) {
            OperationsDiagnosticService::OK => 'ready',
            OperationsDiagnosticService::WARN => 'warning',
            OperationsDiagnosticService::FAIL => 'failed',
            default => $status,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, int>
     */
    private function statusSummary(array $checks): array
    {
        $counts = collect($checks)->countBy('status');

        return [
            'ok' => (int) $counts->get(OperationsDiagnosticService::OK, 0),
            'warnings' => (int) $counts->get(OperationsDiagnosticService::WARN, 0),
            'critical' => (int) $counts->get(OperationsDiagnosticService::FAIL, 0),
        ];
    }
}
