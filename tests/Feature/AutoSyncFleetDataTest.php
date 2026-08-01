<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\WialonReportSyncItem;
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

        foreach ([WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20] as $type) {
            foreach (['100', '101'] as $groupId) {
                WialonReportSyncItem::query()->create([
                    'sync_type' => $type,
                    'report_date' => $date,
                    'wialon_group_id' => $type.'-'.$groupId,
                    'status' => WialonReportSyncItem::STATUS_PENDING,
                ]);
            }
        }

        $calls = [];
        $parametersByCommand = [];
        $kernel = app(Kernel::class);
        Artisan::shouldReceive('call')->andReturnUsing(function (string $command, array $parameters = []) use (&$calls, &$parametersByCommand, $date): int {
            $calls[] = $command;
            $parametersByCommand[$command][] = $parameters;
            $type = match ($command) {
                'fleet:sync-engine-hours-report' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
                default => null,
            };

            if ($type) {
                WialonReportSyncItem::query()
                    ->where('sync_type', $type)
                    ->where('report_date', $date)
                    ->where('status', WialonReportSyncItem::STATUS_PENDING)
                    ->orderBy('id')
                    ->first()
                    ?->update(['status' => WialonReportSyncItem::STATUS_COMPLETED]);
            }

            return 0;
        });
        Artisan::shouldReceive('output')->andReturn('');

        $this->assertSame(0, $kernel->call('fleet:auto-sync', ['--force' => true]));
        $this->assertSame(2, collect($calls)->filter(fn (string $command): bool => $command === 'fleet:sync-engine-hours-report')->count());
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
        $this->assertDatabaseMissing('wialon_report_sync_items', [
            'report_date' => $date,
            'status' => WialonReportSyncItem::STATUS_PENDING,
        ]);
        $this->assertSame('success', Setting::query()->where('key', 'auto_sync_top20_last_status')->value('value'));
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
