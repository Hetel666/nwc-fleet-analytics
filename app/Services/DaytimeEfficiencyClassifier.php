<?php

namespace App\Services;

final class DaytimeEfficiencyClassifier
{
    /**
     * @return array{category: string, detail_status: string}
     */
    public function classify(
        ?float $hours,
        bool $reportRowFound,
        bool $parseSucceeded,
        bool $rawValueIsEmpty
    ): array {
        if (! $reportRowFound) {
            return ['category' => 'no_data_or_not_working', 'detail_status' => 'no_data'];
        }

        if ($rawValueIsEmpty) {
            return ['category' => 'no_data_or_not_working', 'detail_status' => 'empty_value'];
        }

        if (! $parseSucceeded || $hours === null) {
            return ['category' => 'no_data_or_not_working', 'detail_status' => 'parse_error'];
        }

        if ($hours <= 0) {
            return ['category' => 'no_data_or_not_working', 'detail_status' => 'not_working'];
        }

        if ($hours < 1) {
            return ['category' => 'between_0_and_1', 'detail_status' => 'normal'];
        }

        if ($hours < 7) {
            return ['category' => 'between_1_and_7', 'detail_status' => 'normal'];
        }

        if ($hours <= 10) {
            return ['category' => 'between_7_and_10', 'detail_status' => 'normal'];
        }

        return ['category' => 'over_10', 'detail_status' => 'anomaly'];
    }
}
