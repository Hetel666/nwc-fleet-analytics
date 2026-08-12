<?php

namespace Tests\Feature;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AutoSyncFleetDataTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_daily_sync_drains_all_ready_report_items_before_success(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 12:00:00');
        $date = '2026-07-28';
        $this->settings([
            'auto_sync_enabled' => '1',
            'auto_sync_units_enabled' => '0',
            'auto_sync_geofences_enabled' => '0',
            'auto_sync_daily_enabled' => '1',
            'auto_sync_daily_recent_days' => '1',
            'auto_sync_top20_batch_limit' => '1',
        ]);

        $calls = [];
        $parametersByCommand = [];
        $kernel = app(Kernel::class);
        Artisan::shouldReceive('call')->andReturnUsing(function (string $command, array $parameters = []) use (&$calls, &$parametersByCommand): int {
            $calls[] = $command;
            $parametersByCommand[$command][] = $parameters;

            return 0;
        });
        Artisan::shouldReceive('output')->andReturn('');

        $this->assertSame(0, $kernel->call('fleet:auto-sync', ['--force' => true]));
        $this->assertSame(0, collect($calls)->filter(fn (string $command): bool => $command === 'fleet:sync-engine-hours-report')->count());
        $this->assertSame(1, collect($calls)->filter(fn (string $command): bool => $command === 'fleet:queue-efficiency-sync')->count());
        $this->assertSame(1, collect($calls)->filter(fn (string $command): bool => $command === 'fleet:sync-geofence-violations-report')->count());
        $this->assertSame(
            [
                '--from' => '2026-07-28 00:00:00',
                '--to' => '2026-07-28 23:59:59',
                '--force' => true,
            ],
            $parametersByCommand['fleet:sync-geofence-violations-report'][0]
        );
        $this->assertSame('success', Setting::query()->where('key', 'auto_sync_efficiency_last_status')->value('value'));
        $this->assertSame('success', Setting::query()->where('key', 'auto_sync_geofence_violations_last_status')->value('value'));
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->create([
                'key' => $key,
                'value' => $value,
                'is_secret' => false,
            ]);
        }
    }
}
