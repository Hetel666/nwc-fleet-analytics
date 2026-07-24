<?php

namespace Tests\Unit;

use App\Support\DashboardDateRangePolicy;
use InvalidArgumentException;
use Tests\TestCase;

class DashboardDateRangePolicyTest extends TestCase
{
    public function test_it_preserves_inclusive_date_ranges(): void
    {
        $range = app(DashboardDateRangePolicy::class)->normalize([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-07',
        ]);

        $this->assertSame('2026-07-01', $range['from']);
        $this->assertSame('2026-07-07', $range['to']);
        $this->assertSame(7, $range['days']);
    }

    public function test_it_keeps_existing_swap_behavior_for_reversed_ranges_by_default(): void
    {
        $range = app(DashboardDateRangePolicy::class)->normalize([
            'date_from' => '2026-07-07',
            'date_to' => '2026-07-01',
        ]);

        $this->assertSame('2026-07-01', $range['from']);
        $this->assertSame('2026-07-07', $range['to']);
        $this->assertSame(7, $range['days']);
    }

    public function test_it_can_reject_reversed_ranges_when_configured(): void
    {
        config(['fleet.dashboard.reversed_date_range_mode' => 'reject']);

        $this->expectException(InvalidArgumentException::class);

        app(DashboardDateRangePolicy::class)->normalize([
            'date_from' => '2026-07-07',
            'date_to' => '2026-07-01',
        ]);
    }

    public function test_it_can_enforce_context_specific_max_days(): void
    {
        config(['fleet.dashboard.modal_max_period_days' => 7]);

        $this->expectException(InvalidArgumentException::class);

        app(DashboardDateRangePolicy::class)->normalize([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-08',
        ], 'modal');
    }
}
