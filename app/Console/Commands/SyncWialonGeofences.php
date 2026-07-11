<?php

namespace App\Console\Commands;

use App\Models\Geofence;
use App\Models\ProjectWialonGeofenceGroup;
use App\Services\WialonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWialonGeofences extends Command
{
    protected $signature = 'fleet:sync-geofences';

    protected $description = 'Import project geofences from mapped Wialon geofence groups.';

    public function handle(WialonService $wialon): int
    {
        $count = 0;

        foreach (ProjectWialonGeofenceGroup::query()->with('project')->get() as $mapping) {
            try {
                $zones = $wialon->getGeofenceGroupZones($mapping->wialon_resource_id, $mapping->wialon_geofence_group_id);
            } catch (Throwable $exception) {
                Log::warning('Wialon geofence group sync failed', [
                    'project_id' => $mapping->project_id,
                    'resource_id' => $mapping->wialon_resource_id,
                    'group_id' => $mapping->wialon_geofence_group_id,
                    'message' => $exception->getMessage(),
                ]);
                $this->error($exception->getMessage());

                continue;
            }

            foreach ($zones as $zone) {
                $zoneId = (string) ($zone['id'] ?? '');
                if ($zoneId === '') {
                    continue;
                }

                Geofence::updateOrCreate(
                    [
                        'project_id' => $mapping->project_id,
                        'wialon_geofence_id' => $mapping->wialon_resource_id.':'.$zoneId,
                    ],
                    [
                        'name' => $zone['n'] ?? 'Geofence '.$zoneId,
                        'geometry_json' => $this->geometry($zone),
                        'active' => true,
                    ]
                );

                $count++;
            }

            $mapping->update(['zones_count' => count($zones)]);
        }

        $this->info("Synced {$count} Wialon geofences.");

        return self::SUCCESS;
    }

    private function geometry(array $zone): ?array
    {
        $points = $zone['p'] ?? [];
        $type = (int) ($zone['t'] ?? 0);

        if ($type === 1 && count($points) >= 2) {
            return [
                'type' => 'LineString',
                'coordinates' => array_map(fn (array $point): array => [(float) $point['x'], (float) $point['y']], $points),
            ];
        }

        if ($type === 2 && count($points) >= 3) {
            $ring = array_map(fn (array $point): array => [(float) $point['x'], (float) $point['y']], $points);
            if ($ring[0] !== $ring[array_key_last($ring)]) {
                $ring[] = $ring[0];
            }

            return [
                'type' => 'Polygon',
                'coordinates' => [$ring],
            ];
        }

        if ($type === 3 && isset($points[0]['x'], $points[0]['y'])) {
            return $this->circlePolygon((float) $points[0]['x'], (float) $points[0]['y'], (float) ($points[0]['r'] ?? $zone['w'] ?? 50));
        }

        return $this->boundsPolygon($zone['b'] ?? null);
    }

    private function boundsPolygon(?array $bounds): ?array
    {
        if (! isset($bounds['min_x'], $bounds['min_y'], $bounds['max_x'], $bounds['max_y'])) {
            return null;
        }

        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [(float) $bounds['min_x'], (float) $bounds['min_y']],
                [(float) $bounds['max_x'], (float) $bounds['min_y']],
                [(float) $bounds['max_x'], (float) $bounds['max_y']],
                [(float) $bounds['min_x'], (float) $bounds['max_y']],
                [(float) $bounds['min_x'], (float) $bounds['min_y']],
            ]],
        ];
    }

    private function circlePolygon(float $lng, float $lat, float $radiusMeters): array
    {
        $earthRadius = 6371000;
        $latRad = deg2rad($lat);
        $lngRad = deg2rad($lng);
        $distance = $radiusMeters / $earthRadius;
        $ring = [];

        for ($i = 0; $i <= 48; $i++) {
            $bearing = deg2rad($i * 360 / 48);
            $pointLat = asin(sin($latRad) * cos($distance) + cos($latRad) * sin($distance) * cos($bearing));
            $pointLng = $lngRad + atan2(
                sin($bearing) * sin($distance) * cos($latRad),
                cos($distance) - sin($latRad) * sin($pointLat)
            );
            $ring[] = [rad2deg($pointLng), rad2deg($pointLat)];
        }

        return [
            'type' => 'Polygon',
            'coordinates' => [$ring],
        ];
    }
}
