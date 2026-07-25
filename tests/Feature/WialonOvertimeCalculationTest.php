<?php

namespace Tests\Feature;

use App\Services\WialonService;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class WialonOvertimeCalculationTest extends TestCase
{
    public function test_overtime_windows_are_counted_in_asia_baku(): void
    {
        config([
            'fleet_efficiency.timezone' => 'Asia/Baku',
            'fleet_efficiency.overtime' => [
                ['start' => '00:00:00', 'end' => '07:59:59'],
                ['start' => '18:00:00', 'end' => '23:59:59'],
            ],
        ]);

        $method = new ReflectionMethod(WialonService::class, 'overtimeSecondsBetween');
        $method->setAccessible(true);
        $service = new WialonService();

        $this->assertSame(7200, $method->invoke($service, $this->timestamp('2026-07-19 19:00:00'), $this->timestamp('2026-07-19 21:00:00')));
        $this->assertSame(5400, $method->invoke($service, $this->timestamp('2026-07-20 05:30:00'), $this->timestamp('2026-07-20 07:00:00')));
        $this->assertSame(7200, $method->invoke($service, $this->timestamp('2026-07-19 23:00:00'), $this->timestamp('2026-07-20 01:00:00')));
        $this->assertSame(60, $method->invoke($service, $this->timestamp('2026-07-19 18:00:00'), $this->timestamp('2026-07-19 18:01:00')));
    }

    private function timestamp(string $value): int
    {
        return Carbon::parse($value, 'Asia/Baku')->timestamp;
    }
}
