<?php

namespace App\Support;

final class EfficiencyStatus
{
    public const ZERO_TO_ONE = '0_1';

    public const ONE_TO_SEVEN = '1_7';

    public const SEVEN_TO_TEN = '7_10';

    public const OVER_TEN = 'over_10';

    public const NO_DATA = 'no_data';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::ZERO_TO_ONE => '0 - 1 saat arası işləyən',
            self::ONE_TO_SEVEN => '1 - 7 saat arası işləyən',
            self::SEVEN_TO_TEN => '7 - 10 saat arası işləyən',
            self::OVER_TEN => '10 saatdan artıq işləyən',
            self::NO_DATA => 'İşləməyən / Məlumatı olmayan',
        ];
    }

    public static function classify(int $engineSeconds): string
    {
        return match (true) {
            $engineSeconds <= 0 => self::NO_DATA,
            $engineSeconds < 3600 => self::ZERO_TO_ONE,
            $engineSeconds < 25200 => self::ONE_TO_SEVEN,
            $engineSeconds <= 36000 => self::SEVEN_TO_TEN,
            default => self::OVER_TEN,
        };
    }
}
