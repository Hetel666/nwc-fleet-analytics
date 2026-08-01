<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class NighttimeShiftWindow
{
    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public static function forDate(CarbonInterface|string $shiftDate, string $timezone = 'Asia/Baku'): array
    {
        $date = $shiftDate instanceof CarbonInterface
            ? CarbonImmutable::instance($shiftDate)->timezone($timezone)
            : CarbonImmutable::parse($shiftDate, $timezone);

        return [
            'start' => $date->startOfDay()->setTime(18, 0),
            'end' => $date->startOfDay()->addDay()->setTime(7, 59, 59),
        ];
    }

    public static function shiftDateFor(CarbonInterface|string $instant, string $timezone = 'Asia/Baku'): ?string
    {
        $value = $instant instanceof CarbonInterface
            ? CarbonImmutable::instance($instant)->timezone($timezone)
            : CarbonImmutable::parse($instant, $timezone);
        $seconds = ($value->hour * 3600) + ($value->minute * 60) + $value->second;

        if ($seconds >= 18 * 3600) {
            return $value->toDateString();
        }

        if ($seconds <= (7 * 3600) + (59 * 60) + 59) {
            return $value->subDay()->toDateString();
        }

        return null;
    }
}
