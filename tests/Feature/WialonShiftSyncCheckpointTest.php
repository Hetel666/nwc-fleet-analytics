<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\WialonReportSyncItem;
use App\Services\WialonSessionManager;
use App\Services\WialonShiftReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class WialonShiftSyncCheckpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'fleet_efficiency.timezone' => 'Asia/Baku',
            'fleet_efficiency.efficiency_vehicle_types' => ['loader'],
        ]);
    }

    public function test_planner_does_not_duplicate_items_and_skips_completed_without_force(): void
    {
        $group = $this->group('601701901');
        $this->equipment('Unit A', '7001', $group);

        $this->artisan('fleet:plan-shift-sync', ['--from' => '2026-07-19', '--to' => '2026-07-19'])
            ->assertSuccessful();
        $this->artisan('fleet:plan-shift-sync', ['--from' => '2026-07-19', '--to' => '2026-07-19'])
            ->assertSuccessful();

        $this->assertSame(1, WialonReportSyncItem::query()->count());

        $item = WialonReportSyncItem::query()->firstOrFail();
        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_COMPLETED,
            'attempts' => 2,
            'rows_saved' => 7,
            'finished_at' => Carbon::parse('2026-07-19 12:00:00', 'Asia/Baku'),
        ])->save();

        $this->artisan('fleet:plan-shift-sync', ['--from' => '2026-07-19', '--to' => '2026-07-19'])
            ->assertSuccessful();

        $item->refresh();
        $this->assertSame(WialonReportSyncItem::STATUS_COMPLETED, $item->status);
        $this->assertSame(2, $item->attempts);
        $this->assertSame(7, $item->rows_saved);

        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_RETRY,
            'attempts' => 1,
            'next_retry_at' => Carbon::parse('2026-07-19 12:05:00', 'Asia/Baku'),
        ])->save();

        $this->artisan('fleet:plan-shift-sync', ['--from' => '2026-07-19', '--to' => '2026-07-19'])
            ->assertSuccessful();

        $item->refresh();
        $this->assertSame(WialonReportSyncItem::STATUS_RETRY, $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertSame('2026-07-19 12:05:00', $item->next_retry_at?->timezone('Asia/Baku')->toDateTimeString());
    }

    public function test_planner_marks_groups_without_eligible_equipment_as_skipped(): void
    {
        $this->group('601701902');

        $this->artisan('fleet:plan-shift-sync', ['--from' => '2026-07-19', '--to' => '2026-07-19'])
            ->assertSuccessful();

        $item = WialonReportSyncItem::query()->firstOrFail();
        $this->assertSame(WialonReportSyncItem::STATUS_SKIPPED, $item->status);
        $this->assertSame('No eligible equipment for this group.', $item->last_error_message);
    }

    public function test_runner_uses_one_session_for_batch_and_saves_each_completed_checkpoint(): void
    {
        $first = $this->group('601701903');
        $second = $this->group('601701904');
        $this->equipment('Unit A', '7001', $first);
        $this->equipment('Unit B', '7002', $second);
        $this->checkpoint($first);
        $this->checkpoint($second);

        $sessions = $this->fakeSessions();
        $reports = $this->fakeReports([
            '601701903' => $this->report('7001', 'Unit A', 6.0, 1.0),
            '601701904' => $this->report('7002', 'Unit B', 8.0, 0.0),
        ]);

        $this->app->instance(WialonSessionManager::class, $sessions);
        $this->app->instance(WialonShiftReportService::class, $reports);

        $this->artisan('fleet:run-shift-sync', ['--limit' => 2, '--details' => true])
            ->assertSuccessful();

        $this->assertSame(1, $sessions->sidCalls);
        $this->assertSame(1, $sessions->closeCalls);
        $this->assertEqualsCanonicalizing(['601701903', '601701904'], $reports->calls);
        $this->assertSame(2, WialonReportSyncItem::query()->where('status', WialonReportSyncItem::STATUS_COMPLETED)->count());
        $this->assertSame(2, EquipmentDailyStat::query()->count());
    }

    public function test_failed_group_does_not_stop_following_group(): void
    {
        $first = $this->group('601701905');
        $second = $this->group('601701906');
        $this->equipment('Unit A', '7001', $first);
        $this->equipment('Unit B', '7002', $second);
        $this->checkpoint($first);
        $this->checkpoint($second);

        $this->app->instance(WialonSessionManager::class, $this->fakeSessions());
        $this->app->instance(WialonShiftReportService::class, $this->fakeReports([
            '601701905' => new RuntimeException('Wialon report template was not found.'),
            '601701906' => $this->report('7002', 'Unit B', 8.0, 0.0),
        ]));

        $this->artisan('fleet:run-shift-sync', ['--limit' => 2])
            ->assertFailed();

        $this->assertSame(WialonReportSyncItem::STATUS_FAILED, $this->item('601701905')->status);
        $this->assertSame(WialonReportSyncItem::STATUS_COMPLETED, $this->item('601701906')->status);
        $this->assertSame(1, EquipmentDailyStat::query()->count());
    }

    public function test_temporary_error_gets_retry_backoff_and_becomes_failed_after_three_attempts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'Asia/Baku'));

        $group = $this->group('601701907');
        $this->equipment('Unit A', '7001', $group);
        $this->checkpoint($group);

        $this->app->instance(WialonSessionManager::class, $this->fakeSessions());
        $this->app->instance(WialonShiftReportService::class, $this->fakeReports([
            '601701907' => new RuntimeException('Wialon API error 1004: temporary report error'),
        ]));

        $this->artisan('fleet:run-shift-sync', ['--limit' => 1])
            ->assertSuccessful();

        $item = $this->item('601701907');
        $this->assertSame(WialonReportSyncItem::STATUS_RETRY, $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertSame('2026-07-21 10:05:00', $item->next_retry_at?->timezone('Asia/Baku')->toDateTimeString());

        $item->forceFill([
            'status' => WialonReportSyncItem::STATUS_RETRY,
            'attempts' => 2,
            'next_retry_at' => Carbon::parse('2026-07-21 09:59:00', 'Asia/Baku'),
        ])->save();

        $this->artisan('fleet:run-shift-sync', ['--limit' => 1])
            ->assertFailed();

        $item->refresh();
        $this->assertSame(WialonReportSyncItem::STATUS_FAILED, $item->status);
        $this->assertSame(3, $item->attempts);
        $this->assertNull($item->next_retry_at);
    }

    public function test_auth_retry_is_used_once_and_remaining_package_items_are_deferred(): void
    {
        $first = $this->group('601701908');
        $second = $this->group('601701909');
        $this->equipment('Unit A', '7001', $first);
        $this->equipment('Unit B', '7002', $second);
        $this->checkpoint($first);
        $this->checkpoint($second);

        $sessions = $this->fakeSessions();
        $reports = $this->fakeReports([
            '601701908' => new RuntimeException('Wialon API error 1003: token/login temporarily unavailable'),
            '601701909' => $this->report('7002', 'Unit B', 8.0, 0.0),
        ]);

        $this->app->instance(WialonSessionManager::class, $sessions);
        $this->app->instance(WialonShiftReportService::class, $reports);

        $this->artisan('fleet:run-shift-sync', ['--limit' => 2])
            ->assertSuccessful();

        $this->assertSame(1, $sessions->reauthorizeCalls);
        $this->assertSame(WialonReportSyncItem::STATUS_RETRY, $this->item('601701908')->status);
        $this->assertSame(WialonReportSyncItem::STATUS_RETRY, $this->item('601701909')->status);
        $this->assertSame(['601701908', '601701908'], $reports->calls);
    }

    public function test_retry_command_moves_only_selected_failed_items(): void
    {
        $first = $this->group('601701910');
        $second = $this->group('601701911');
        $this->checkpoint($first, WialonReportSyncItem::STATUS_FAILED);
        $this->checkpoint($second, WialonReportSyncItem::STATUS_FAILED);

        $this->artisan('fleet:retry-shift-sync', ['--date' => '2026-07-19', '--group' => '601701910'])
            ->assertSuccessful();

        $this->assertSame(WialonReportSyncItem::STATUS_RETRY, $this->item('601701910')->status);
        $this->assertSame(WialonReportSyncItem::STATUS_FAILED, $this->item('601701911')->status);
    }

    private function group(string $wialonGroupId): ProjectWialonGroup
    {
        $project = Project::query()->create(['name' => 'Project '.$wialonGroupId, 'active' => true]);

        return ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => $wialonGroupId,
            'name' => 'Group '.$wialonGroupId,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
    }

    private function equipment(string $name, string $wialonId, ProjectWialonGroup $group): Equipment
    {
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Loader']);

        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => $wialonId,
            'equipment_type_id' => $type->id,
            'project_id' => $group->project_id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
    }

    private function checkpoint(ProjectWialonGroup $group, string $status = WialonReportSyncItem::STATUS_PENDING): WialonReportSyncItem
    {
        return WialonReportSyncItem::query()->create([
            'sync_type' => WialonReportSyncItem::TYPE_SHIFT_EFFICIENCY,
            'report_date' => '2026-07-19',
            'wialon_group_id' => $group->wialon_group_id,
            'wialon_group_name' => $group->name,
            'status' => $status,
            'attempts' => 0,
        ]);
    }

    private function item(string $group): WialonReportSyncItem
    {
        return WialonReportSyncItem::query()->where('wialon_group_id', $group)->firstOrFail();
    }

    private function report(string $unitId, string $unitName, float $daytime, float $overtime): array
    {
        $total = $daytime + $overtime;

        return [
            'resource_id' => 1,
            'template_id' => 18,
            'template_name' => 'Qrup report novbe 24 saat (api)',
            'from' => CarbonImmutable::parse('2026-07-19 00:00:00', 'Asia/Baku'),
            'to' => CarbonImmutable::parse('2026-07-19 23:59:59', 'Asia/Baku'),
            'tables' => [[
                'index' => 0,
                'table' => [
                    'name' => 'Qrup report novbe 24 saat (api)',
                    'header' => ['Unit', 'Date', 'Daytime', 'Overtime', 'Total'],
                    'rows' => 1,
                ],
                'rows' => [[
                    'uid' => $unitId,
                    'c' => [$unitName, '2026-07-19', $daytime, $overtime, $total],
                ]],
            ]],
        ];
    }

    private function fakeSessions(): WialonSessionManager
    {
        return new class extends WialonSessionManager
        {
            public int $sidCalls = 0;
            public int $reauthorizeCalls = 0;
            public int $closeCalls = 0;

            public function __construct()
            {
            }

            public function sid(): string
            {
                $this->sidCalls++;

                return 'sid-1';
            }

            public function reauthorizeOnce(): ?string
            {
                $this->reauthorizeCalls++;

                return $this->reauthorizeCalls === 1 ? 'sid-2' : null;
            }

            public function close(): void
            {
                $this->closeCalls++;
            }
        };
    }

    /**
     * @param array<string, array<string, mixed>|RuntimeException> $responses
     */
    private function fakeReports(array $responses): WialonShiftReportService
    {
        return new class($responses) extends WialonShiftReportService
        {
            public array $calls = [];

            public function __construct(private array $responses)
            {
            }

            public function executeForGroupWithSession(ProjectWialonGroup|int|string $group, CarbonInterface $from, CarbonInterface $to, string $sid): array
            {
                $groupId = $group instanceof ProjectWialonGroup ? (string) $group->wialon_group_id : (string) $group;
                $this->calls[] = $groupId;
                $response = $this->responses[$groupId] ?? null;

                if ($response instanceof RuntimeException) {
                    throw $response;
                }

                if (is_array($response)) {
                    return $response;
                }

                throw new RuntimeException('Unexpected fake report group.');
            }
        };
    }
}
