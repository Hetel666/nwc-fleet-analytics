<?php

namespace App\Support;

final class MonthlyEfficiencyStatus
{
    public const CRITICAL_LOW = 'critical_low';

    public const LOW = 'low';

    public const NORMAL = 'normal';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::CRITICAL_LOW => 'Kritik aşağı',
            self::LOW => 'Aşağı',
            self::NORMAL => 'Normal',
        ];
    }

    /** @return array<string, string> */
    public static function colors(): array
    {
        return [
            self::CRITICAL_LOW => '#dc2626',
            self::LOW => '#f97316',
            self::NORMAL => '#24b35b',
        ];
    }

    public static function classify(float $hours): string
    {
        return match (true) {
            $hours <= 150.0 => self::CRITICAL_LOW,
            $hours < 200.0 => self::LOW,
            default => self::NORMAL,
        };
    }

    public static function classifyForPeriod(float $hours, int $days): string
    {
        $days = max(1, $days);
        $criticalLimit = $days * 5.0;
        $normalLimit = $days * 7.0;

        return match (true) {
            $hours < $criticalLimit => self::CRITICAL_LOW,
            $hours <= $normalLimit => self::LOW,
            default => self::NORMAL,
        };
    }
}
