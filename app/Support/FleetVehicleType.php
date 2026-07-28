<?php

namespace App\Support;

use Illuminate\Support\Str;

final class FleetVehicleType
{
    public const DUMP_TRUCK = 'dump_truck';

    public const BULLDOZER = 'bulldozer';

    public const EXCAVATOR = 'excavator';

    public const LOADER = 'loader';

    public const BACKHOE_LOADER = 'backhoe_loader';

    public const ROAD_GRADER = 'road_grader';

    public const ROAD_ROLLER = 'road_roller';

    public const ANALYTICS_TYPES = [
        self::BULLDOZER,
        self::EXCAVATOR,
        self::LOADER,
        self::BACKHOE_LOADER,
        self::ROAD_GRADER,
        self::ROAD_ROLLER,
    ];

    public const EFFICIENCY_TYPES = [
        self::DUMP_TRUCK,
        self::EXCAVATOR,
        self::ROAD_GRADER,
        self::LOADER,
        self::BACKHOE_LOADER,
        self::ROAD_ROLLER,
    ];

    public const FOREIGN_GEOFENCE_TYPES = [
        self::DUMP_TRUCK,
        self::BULLDOZER,
        self::EXCAVATOR,
        self::ROAD_GRADER,
        self::LOADER,
        self::BACKHOE_LOADER,
        self::ROAD_ROLLER,
    ];

    public const AVERAGE_ENGINE_HOURS_TYPES = self::ANALYTICS_TYPES;

    /**
     * Current business rule: mileage averages still use Dump Truck only.
     */
    public const AVERAGE_MILEAGE_TYPES = [
        self::DUMP_TRUCK,
    ];

    public const TOP_WORKING_TYPES = [
        self::BULLDOZER,
        self::EXCAVATOR,
        self::ROAD_GRADER,
        self::LOADER,
        self::BACKHOE_LOADER,
        self::ROAD_ROLLER,
    ];

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'bakhoe_loader' => self::BACKHOE_LOADER,
            'bakhoe-loader' => self::BACKHOE_LOADER,
            'bakhoe loader' => self::BACKHOE_LOADER,
            'backhoe-loader' => self::BACKHOE_LOADER,
            'backhoe loader' => self::BACKHOE_LOADER,
            'backhoe_loader' => self::BACKHOE_LOADER,
            'buldozer' => self::BULLDOZER,
            'road-grader' => self::ROAD_GRADER,
            'road grader' => self::ROAD_GRADER,
            'road_grader' => self::ROAD_GRADER,
            'road-roller' => self::ROAD_ROLLER,
            'road roller' => self::ROAD_ROLLER,
            'road_roller' => self::ROAD_ROLLER,
            'dump-truck' => self::DUMP_TRUCK,
            'dump truck' => self::DUMP_TRUCK,
            'dump_truck' => self::DUMP_TRUCK,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::DUMP_TRUCK => 'Dump Truck',
            self::BULLDOZER => 'Bulldozer',
            self::EXCAVATOR => 'Excavator',
            self::LOADER => 'Loader',
            self::BACKHOE_LOADER => 'Backhoe Loader',
            self::ROAD_GRADER => 'Road Grader',
            self::ROAD_ROLLER => 'Road Roller',
        ];
    }

    public static function normalize(?string $value): string
    {
        $code = Str::of((string) $value)
            ->squish()
            ->lower()
            ->replace(['-', ' '], '_')
            ->value();

        return self::aliases()[$code] ?? $code;
    }

    public static function slug(?string $value): string
    {
        return str_replace('_', '-', self::normalize($value));
    }

    public static function label(?string $value): string
    {
        $code = self::normalize($value);

        return self::labels()[$code] ?? Str::headline(str_replace(['-', '_'], ' ', (string) $value));
    }

    public static function display(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
        }

        $code = self::normalize($value);

        return self::labels()[$code] ?? $value;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    public static function slugs(array $values): array
    {
        return collect($values)
            ->map(fn (string $value): string => self::slug($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    public static function names(array $values): array
    {
        return collect($values)
            ->map(fn (string $value): string => self::label($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function configAliases(): array
    {
        return collect(self::aliases())
            ->flatMap(fn (string $target, string $alias): array => [
                $alias => $target,
                str_replace('_', '-', $alias) => str_replace('_', '-', $target),
            ])
            ->all();
    }
}
