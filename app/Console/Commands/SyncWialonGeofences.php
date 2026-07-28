<?php

namespace App\Console\Commands;

use App\Models\Geofence;
use App\Models\Project;
use App\Services\GeofenceNameNormalizer;
use App\Services\WialonService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWialonGeofences extends Command
{
    protected $signature = 'fleet:sync-geofences';

    protected $description = 'Import project geofences from configured Wialon geofence IDs.';

    public function handle(WialonService $wialon, GeofenceNameNormalizer $normalizer): int
    {
        $projects = Project::query()->get()->keyBy('name');
        $configuredGeofences = config('wialon_projects.project_geofence_ids', []);

        if (! is_array($configuredGeofences) || $configuredGeofences === []) {
            $this->warn('No Wialon geofence IDs configured.');
            return self::SUCCESS;
        }

        $count = $this->syncConfiguredGeofences($wialon, $normalizer, $projects, $configuredGeofences);

        $this->info("Synced {$count} Wialon geofences.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Project>  $projects
     * @param  array<string, array<int, string>>  $configuredGeofences
     */
    private function syncConfiguredGeofences(
        WialonService $wialon,
        GeofenceNameNormalizer $normalizer,
        Collection $projects,
        array $configuredGeofences
    ): int {
        $count = 0;
        $projectByGeofenceId = [];
        $zoneIdsByResourceId = [];

        foreach ($configuredGeofences as $projectName => $geofenceIds) {
            foreach ((array) $geofenceIds as $wialonGeofenceId) {
                [$resourceId, $zoneId] = $this->splitWialonGeofenceId((string) $wialonGeofenceId);

                if ($resourceId === null || $zoneId === null) {
                    continue;
                }

                $fullId = $resourceId.':'.$zoneId;
                $projectByGeofenceId[$fullId] = (string) $projectName;
                $zoneIdsByResourceId[$resourceId][] = $zoneId;
            }
        }

        foreach ($zoneIdsByResourceId as $resourceId => $zoneIds) {
            try {
                $zones = $wialon->getGeofenceZonesByIds($resourceId, array_values(array_unique($zoneIds)));
            } catch (Throwable $exception) {
                Log::warning('Configured Wialon geofence sync failed', [
                    'resource_id' => $resourceId,
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

                $wialonGeofenceId = $resourceId.':'.$zoneId;
                $projectName = $projectByGeofenceId[$wialonGeofenceId] ?? null;
                $project = is_string($projectName) ? $projects->get($projectName) : null;

                if (! $project instanceof Project) {
                    continue;
                }

                Geofence::updateOrCreate(
                    ['wialon_geofence_id' => $wialonGeofenceId],
                    [
                        'project_id' => $project->id,
                        'name' => $zone['n'] ?? 'Geofence '.$zoneId,
                        'normalized_name' => $normalizer->normalize($zone['n'] ?? 'Geofence '.$zoneId),
                        'geometry_json' => $this->geometry($zone),
                        'active' => true,
                    ]
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitWialonGeofenceId(string $wialonGeofenceId): array
    {
        $parts = explode(':', trim($wialonGeofenceId), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
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
