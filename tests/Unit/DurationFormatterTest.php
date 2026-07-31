<?php

namespace Tests\Unit;

use App\Support\DurationFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DurationFormatterTest extends TestCase
{
    #[DataProvider('durationProvider')]
    public function test_it_formats_seconds(int $seconds, string $days, string $hours, string $decimal): void
    {
        $this->assertSame($days, DurationFormatter::format($seconds, DurationFormatter::DAYS_HMS));
        $this->assertSame($hours, DurationFormatter::format($seconds, DurationFormatter::HOURS_HMS));
        $this->assertSame($decimal, DurationFormatter::format($seconds, DurationFormatter::DECIMAL_HOURS));
    }

    public static function durationProvider(): array
    {
        return [
            [0, '00:00:00', '00:00:00', '0.00'],
            [1, '00:00:01', '00:00:01', '0.00'],
            [3599, '00:59:59', '00:59:59', '1.00'],
            [3600, '01:00:00', '01:00:00', '1.00'],
            [86399, '23:59:59', '23:59:59', '24.00'],
            [86400, '1 gün 00:00:00', '24:00:00', '24.00'],
            [90061, '1 gün 01:01:01', '25:01:01', '25.02'],
            [3_600_001, '41 gün 16:00:01', '1000:00:01', '1000.00'],
        ];
    }

    public function test_it_normalizes_unknown_format_to_decimal_hours(): void
    {
        $this->assertSame(DurationFormatter::DECIMAL_HOURS, DurationFormatter::normalize('bad'));
        $this->assertSame('1.00', DurationFormatter::format(3600, 'bad'));
    }

    public function test_it_rejects_negative_seconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DurationFormatter::format(-1);
    }
}
