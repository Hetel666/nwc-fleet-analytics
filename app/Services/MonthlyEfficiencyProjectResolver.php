<?php

namespace App\Services;

use App\Models\Geofence;
use App\Models\Project;
use App\Models\WialonGeofence;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MonthlyEfficiencyProjectResolver
{
    private ?Collection $projects = null;

    private ?Collection $localGeofences = null;

    private ?Collection $wialonGeofences = null;

    /**
     * @param  array<string, mixed>  $fact
     * @return array{project_id: int, project: string, source: string}
     */
    public function resolve(array $fact): array
    {
        $raw = $this->decodeRaw($fact['raw_row_json'] ?? null);

        $byLocation = $this->projectFromLocationText($raw);
        if ($byLocation !== null) {
            return [
                'project_id' => (int) $byLocation->id,
                'project' => (string) $byLocation->name,
                'source' => 'wialon_location',
            ];
        }

        $byPosition = $this->projectFromCoordinates($raw);
        if ($byPosition !== null) {
            return [
                'project_id' => (int) $byPosition['project_id'],
                'project' => (string) $byPosition['project'],
                'source' => $byPosition['source'],
            ];
        }

        return [
            'project_id' => (int) $fact['project_id'],
            'project' => (string) $fact['project'],
            'source' => 'group_fallback',
        ];
    }

    private function projectFromLocationText(?array $raw): ?Project
    {
        foreach ($this->locationTexts($raw)->reverse() as $text) {
            $prefix = trim(Str::before($text, ':'));
            $normalizedPrefix = $this->normalize($prefix);

            if ($normalizedPrefix === '' || $this->looksLikeDateTime($prefix)) {
                continue;
            }

            $exact = $this->projects()->first(
                fn (Project $project): bool => $this->normalize((string) $project->name) === $normalizedPrefix
            );

            if ($exact instanceof Project) {
                return $exact;
            }

            $contains = $this->projects()->first(function (Project $project) use ($normalizedPrefix): bool {
                $projectName = $this->normalize((string) $project->name);

                return $projectName !== ''
                    && ($normalizedPrefix === $projectName
                        || str_contains($normalizedPrefix, $projectName)
                        || str_contains($projectName, $normalizedPrefix));
            });

            if ($contains instanceof Project) {
                return $contains;
            }
        }

        return null;
    }

    /** @return Collection<int, string> */
    private function locationTexts(?array $raw): Collection
    {
        return collect($raw['c'] ?? $raw['cells'] ?? [])
            ->map(fn (mixed $cell): string => is_array($cell)
                ? trim((string) ($cell['t'] ?? ''))
                : trim((string) $cell))
            ->filter(fn (string $text): bool => $text !== '' && str_contains($text, ':'))
            ->values();
    }

    /** @return array{project_id: int, project: string, source: string}|null */
    private function projectFromCoordinates(?array $raw): ?array
    {
        foreach ($this->positions($raw)->reverse() as $position) {
            $local = $this->matchingLocalGeofence($position['lng'], $position['lat']);
            if ($local !== null) {
                return [
                    'project_id' => (int) $local['project_id'],
                    'project' => (string) $local['project'],
                    'source' => 'local_geofence',
                ];
            }

            $wialon = $this->matchingWialonGeofence($position['lng'], $position['lat']);
            if ($wialon !== null) {
                return [
                    'project_id' => (int) $wialon['project_id'],
                    'project' => (string) $wialon['project'],
                    'source' => 'wialon_geofence',
                ];
            }
        }

        return null;
    }

    /** @return Collection<int, array{lng: float, lat: float}> */
    private function positions(?array $raw): Collection
    {
        return collect($raw['c'] ?? $raw['cells'] ?? [])
            ->filter(fn (mixed $cell): bool => is_array($cell) && is_numeric($cell['x'] ?? null) && is_numeric($cell['y'] ?? null))
            ->map(fn (array $cell): array => [
                'lng' => (float) $cell['x'],
                'lat' => (float) $cell['y'],
            ])
            ->values();
    }

    /** @return array{project_id: int, project: string, area: float}|null */
    private function matchingLocalGeofence(float $lng, float $lat): ?array
    {
        return $this->localGeofences()
            ->filter(fn (array $geofence): bool => $this->geometryContains($geofence['geometry'], $lng, $lat))
            ->sortBy('area')
            ->first();
    }

    /** @return array{project_id: int, project: string, area: float}|null */
    private function matchingWialonGeofence(float $lng, float $lat): ?array
    {
        return $this->wialonGeofences()
            ->filter(fn (array $geofence): bool => $this->geometryContains($geofence['geometry'], $lng, $lat)
                || $this->boundsContain($geofence['geometry']['b'] ?? null, $lng, $lat))
            ->sortBy('area')
            ->first();
    }

    private function geometryContains(?array $geometry, float $lng, float $lat): bool
    {
        if ($geometry === null) {
            return false;
        }

        $type = strtolower((string) ($geometry['type'] ?? ''));
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'polygon' && is_array($coordinates)) {
            return $this->pointInPolygon($lng, $lat, $coordinates);
        }

        if ($type === 'multipolygon' && is_array($coordinates)) {
            foreach ($coordinates as $polygon) {
                if (is_array($polygon) && $this->pointInPolygon($lng, $lat, $polygon)) {
                    return true;
                }
            }
        }

        foreach (['p', 'points', 'geometry'] as $key) {
            if (is_array($geometry[$key] ?? null) && $this->pointInPolygon($lng, $lat, [$geometry[$key]])) {
                return true;
            }
        }

        return false;
    }

    private function boundsContain(mixed $bounds, float $lng, float $lat): bool
    {
        return is_array($bounds)
            && is_numeric($bounds['min_x'] ?? null)
            && is_numeric($bounds['max_x'] ?? null)
            && is_numeric($bounds['min_y'] ?? null)
            && is_numeric($bounds['max_y'] ?? null)
            && $lng >= (float) $bounds['min_x']
            && $lng <= (float) $bounds['max_x']
            && $lat >= (float) $bounds['min_y']
            && $lat <= (float) $bounds['max_y'];
    }

    private function pointInPolygon(float $lng, float $lat, array $polygon): bool
    {
        $outerRing = $polygon[0] ?? [];

        if (! is_array($outerRing) || count($outerRing) < 3 || ! $this->pointInRing($lng, $lat, $outerRing)) {
            return false;
        }

        foreach (array_slice($polygon, 1) as $hole) {
            if (is_array($hole) && $this->pointInRing($lng, $lat, $hole)) {
                return false;
            }
        }

        return true;
    }

    private function pointInRing(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $pi = $ring[$i];
            $pj = $ring[$j];

            if (! is_array($pi) || ! is_array($pj) || count($pi) < 2 || count($pj) < 2) {
                continue;
            }

            $xi = (float) $pi[0];
            $yi = (float) $pi[1];
            $xj = (float) $pj[0];
            $yj = (float) $pj[1];
            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function area(?array $geometry): float
    {
        $bounds = $geometry['b'] ?? null;

        if ($this->validBounds($bounds)) {
            return max(0.0000001, ((float) $bounds['max_x'] - (float) $bounds['min_x'])
                * ((float) $bounds['max_y'] - (float) $bounds['min_y']));
        }

        return (float) PHP_INT_MAX;
    }

    private function validBounds(mixed $bounds): bool
    {
        return is_array($bounds)
            && is_numeric($bounds['min_x'] ?? null)
            && is_numeric($bounds['max_x'] ?? null)
            && is_numeric($bounds['min_y'] ?? null)
            && is_numeric($bounds['max_y'] ?? null);
    }

    private function decodeRaw(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return Collection<int, Project> */
    private function projects(): Collection
    {
        return $this->projects ??= Project::query()
            ->where('active', true)
            ->excludeFromOperationalDashboard()
            ->get(['id', 'name']);
    }

    /** @return Collection<int, array{project_id: int, project: string, geometry: array|null, area: float}> */
    private function localGeofences(): Collection
    {
        return $this->localGeofences ??= Geofence::query()
            ->join('projects', 'projects.id', '=', 'geofences.project_id')
            ->where('geofences.active', true)
            ->where('projects.active', true)
            ->select('geofences.geometry_json', 'projects.id as project_id', 'projects.name as project')
            ->get()
            ->map(fn (Geofence $geofence): array => [
                'project_id' => (int) $geofence->project_id,
                'project' => (string) $geofence->project,
                'geometry' => $geofence->geometry_json,
                'area' => $this->area($geofence->geometry_json),
            ]);
    }

    /** @return Collection<int, array{project_id: int, project: string, geometry: array|null, area: float}> */
    private function wialonGeofences(): Collection
    {
        return $this->wialonGeofences ??= WialonGeofence::query()
            ->join('projects', 'projects.id', '=', 'wialon_geofences.linked_project_id')
            ->where('wialon_geofences.is_active', true)
            ->where('projects.active', true)
            ->whereNotNull('wialon_geofences.linked_project_id')
            ->select('wialon_geofences.raw_geometry_json', 'projects.id as project_id', 'projects.name as project')
            ->get()
            ->map(fn (WialonGeofence $geofence): array => [
                'project_id' => (int) $geofence->project_id,
                'project' => (string) $geofence->project,
                'geometry' => $geofence->raw_geometry_json,
                'area' => $this->area($geofence->raw_geometry_json),
            ]);
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));

        return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    }

    private function looksLikeDateTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $value) === 1;
    }
}
