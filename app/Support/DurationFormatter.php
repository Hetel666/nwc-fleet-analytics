<?php

namespace App\Support;

use InvalidArgumentException;

final class DurationFormatter
{
    public const DAYS_HMS = 'days_hms';

    public const HOURS_HMS = 'hours_hms';

    public const DECIMAL_HOURS = 'decimal_hours';

    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return [
            self::DAYS_HMS,
            self::HOURS_HMS,
            self::DECIMAL_HOURS,
        ];
    }

    public static function normalize(?string $format): string
    {
        return in_array($format, self::allowed(), true) ? $format : self::DECIMAL_HOURS;
    }

    public static function format(int $seconds, string $format = self::DECIMAL_HOURS): string
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Duration seconds cannot be negative.');
        }

        return match (self::normalize($format)) {
            self::DAYS_HMS => self::daysHms($seconds),
            self::HOURS_HMS => self::hoursHms($seconds),
            default => number_format($seconds / 3600, 2, '.', ''),
        };
    }

    public static function excelValue(int $seconds, string $format = self::DECIMAL_HOURS): mixed
    {
        if (self::normalize($format) === self::DECIMAL_HOURS) {
            return round($seconds / 3600, 2);
        }

        return self::format($seconds, $format);
    }

    public static function labelSuffix(?string $format = self::DECIMAL_HOURS): string
    {
        return match (self::normalize($format)) {
            self::DAYS_HMS => 'gün saat:dəqiqə:saniyə',
            self::HOURS_HMS => 'saat:dəqiqə:saniyə',
            default => 'saat',
        };
    }

    private static function daysHms(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $remainder = $seconds % 86400;
        $time = sprintf(
            '%02d:%02d:%02d',
            intdiv($remainder, 3600),
            intdiv($remainder % 3600, 60),
            $remainder % 60
        );

        return $days > 0 ? $days.' gün '.$time : $time;
    }

    private static function hoursHms(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }
}
