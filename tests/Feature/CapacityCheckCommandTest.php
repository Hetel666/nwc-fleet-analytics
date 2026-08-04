<?php

namespace Tests\Feature;

use App\Models\DashboardExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CapacityCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    public function test_capacity_check_audits_test_paths_without_mutating_them(): void
    {
        $root = $this->test_directory('capacity-read-only');
        $exports = $this->test_directory('capacity-exports');
        $backups = $this->test_directory('capacity-backups');
        $dockerLogs = $this->test_directory('capacity-docker-logs');

        File::put($exports.'/export.xlsx', str_repeat('x', 128));
        File::put($backups.'/backup.sql.gz', str_repeat('b', 64));
        File::put($dockerLogs.'/container-json.log', str_repeat('l', 32));

        DashboardExport::query()->create([
            'user_id' => User::factory()->create(['active' => true])->id,
            'block' => 'overview',
            'filters' => [],
            'status' => DashboardExport::STATUS_READY,
            'disk' => 'dashboard_exports',
            'path' => 'export.xlsx',
            'file_name' => 'export.xlsx',
            'file_size' => 128,
            'completed_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $exitCode = Artisan::call('fleet:capacity-check', [
            '--disk-path' => $root,
            '--export-root' => $exports,
            '--backup-path' => $backups,
            '--docker-logs-path' => $dockerLogs,
            '--json' => true,
            '--no-alert' => true,
        ]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($report);
        $this->assertSame(128, $report['directories']['dashboard_exports']['bytes']);
        $this->assertSame(32, $report['directories']['docker_logs']['bytes']);
        $this->assertArrayHasKey('database', $report);

        $this->assertFileExists($exports.'/export.xlsx');
        $this->assertFileExists($backups.'/backup.sql.gz');
        $this->assertFileExists($dockerLogs.'/container-json.log');
    }

    public function test_capacity_check_logs_threshold_alerts_with_cooldown(): void
    {
        Cache::flush();
        Log::spy();
        config([
            'capacity.alerts.thresholds' => [1, 70, 80, 85, 90],
            'capacity.alerts.cooldown_minutes' => 60,
            'capacity.dashboard_exports.max_bytes' => 1,
        ]);

        $exports = $this->test_directory('capacity-alert-exports');
        File::put($exports.'/export.xlsx', str_repeat('x', 128));

        $this->artisan('fleet:capacity-check', [
            '--disk-path' => $exports,
            '--export-root' => $exports,
            '--backup-path' => $this->test_directory('capacity-alert-backups'),
            '--docker-logs-path' => $this->test_directory('capacity-alert-docker'),
        ])->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->with('Fleet capacity threshold crossed.', \Mockery::on(
                fn (array $payload): bool => ($payload['metric'] ?? null) === 'dashboard_exports'
                    && ($payload['threshold'] ?? null) === 90
            ))
            ->once();
    }

    private function test_directory(string $name): string
    {
        $path = storage_path('framework/testing/'.$name.'-'.uniqid());
        File::ensureDirectoryExists($path);
        $this->paths[] = $path;

        return $path;
    }
}
