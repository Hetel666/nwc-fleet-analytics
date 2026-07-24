<?php

namespace App\Console\Commands;

use App\Services\DashboardService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ProfileDashboard extends Command
{
    protected $signature = 'dashboard:profile
        {--project= : Project ID}
        {--date-from= : Start date, YYYY-MM-DD}
        {--date-to= : End date, YYYY-MM-DD}
        {--widget= : Single Dashboard payload key}
        {--repetitions=1 : Number of read-only repetitions}
        {--json : Print machine-readable JSON}';

    protected $description = 'Profile Dashboard local-read services without changing data.';

    public function handle(DashboardService $dashboard): int
    {
        $filters = [
            'project_id' => $this->option('project'),
            'date_from' => $this->option('date-from'),
            'date_to' => $this->option('date-to'),
        ];
        $widget = $this->option('widget') ? (string) $this->option('widget') : null;
        $repetitions = max(1, (int) $this->option('repetitions'));
        $profiles = [];

        for ($iteration = 1; $iteration <= $repetitions; $iteration++) {
            try {
                if ($widget) {
                    $payload = $dashboard->getDashboardProfileWidget($filters, $widget);
                } else {
                    $payload = $dashboard->getDashboardProfile($filters);
                }
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            } catch (Throwable $exception) {
                $this->error($exception::class.': '.$exception->getMessage());

                return self::FAILURE;
            }

            $profile = $dashboard->dashboardPerformanceProfile();
            $profile['iteration'] = $iteration;
            $profile['filters'] = $payload['filters'] ?? $filters;
            $profile['widget'] = $widget;
            $profiles[] = $profile;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Run', 'Operation', 'Duration ms', 'Queries', 'DB ms', 'Peak MB', 'Result KB'],
            array_map(fn (array $profile): array => [
                $profile['iteration'] ?? 1,
                $profile['operation'] ?? '',
                number_format((float) ($profile['duration_ms'] ?? 0), 2, '.', ''),
                (int) ($profile['query_count'] ?? 0),
                number_format((float) ($profile['db_time_ms'] ?? 0), 2, '.', ''),
                number_format((float) ($profile['peak_memory_mb'] ?? 0), 2, '.', ''),
                number_format((float) ($profile['result_size_kb'] ?? 0), 2, '.', ''),
            ], $profiles)
        );

        $this->newLine();
        $this->line('Segments');

        foreach ($profiles as $profile) {
            $this->line('Run '.($profile['iteration'] ?? 1).':');
            $this->table(
                ['Segment', 'Duration ms', 'Queries', 'DB ms', 'Memory MB', 'Status'],
                array_map(fn (array $segment): array => [
                    $segment['name'] ?? '',
                    number_format((float) ($segment['duration_ms'] ?? 0), 2, '.', ''),
                    (int) ($segment['query_count'] ?? 0),
                    number_format((float) ($segment['db_time_ms'] ?? 0), 2, '.', ''),
                    number_format((float) ($segment['memory_delta_mb'] ?? 0), 2, '.', ''),
                    $segment['status'] ?? 'ok',
                ], $profile['segments'] ?? [])
            );
        }

        return self::SUCCESS;
    }
}
