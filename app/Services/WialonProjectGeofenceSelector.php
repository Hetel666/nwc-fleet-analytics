<?php

namespace App\Services;

use RuntimeException;

class WialonProjectGeofenceSelector
{
    /** @var array<string, string> */
    private array $resolvedGroupTokens = [];

    public function resolveProjectGroupToken(
        WialonService $wialon,
        int $resourceId,
        ?string $sessionId = null
    ): string {
        $cacheKey = $resourceId.'|'.($sessionId ?? 'default');

        if (isset($this->resolvedGroupTokens[$cacheKey])) {
            return $this->resolvedGroupTokens[$cacheKey];
        }

        $resource = $wialon->getResource($resourceId, $sessionId);
        $groups = is_array($resource['zg'] ?? null) ? $resource['zg'] : [];
        $preferredNames = collect(config('fleet.wialon.project_geofence_group_names', ['projects']))
            ->map(fn (mixed $name): string => $this->normalizeName((string) $name))
            ->filter()
            ->values();

        foreach ($preferredNames as $preferredName) {
            foreach ($groups as $key => $group) {
                if (! is_array($group) || $this->normalizeName((string) ($group['n'] ?? '')) !== $preferredName) {
                    continue;
                }

                $groupId = (int) ($group['id'] ?? $key);

                if ($groupId > 0 && count($group['zns'] ?? []) > 0) {
                    return $this->resolvedGroupTokens[$cacheKey] = sprintf('gr%d_%d', $resourceId, $groupId);
                }
            }
        }

        throw new RuntimeException('The active Wialon project geofence group was not found. The report snapshot was not changed.');
    }

    /**
     * Replace stale group references in a transient report copy and ensure the
     * current project's home geofences are selected as a fallback.
     *
     * @param  array<string, mixed>  $template
     * @param  array<int, string>  $homeGeofenceIds
     * @return array<string, mixed>
     */
    public function apply(
        array $template,
        string $projectGroupToken,
        array $homeGeofenceIds,
        bool $removeDurationFilter = false
    ): array {
        $matched = false;

        foreach ($template['tbl'] ?? [] as $index => $table) {
            if (($table['n'] ?? null) !== 'unit_group_zones_visit') {
                continue;
            }

            $parameters = json_decode((string) ($table['p'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $selected = collect(explode(',', (string) ($parameters['geozones'] ?? '')))
                ->map(fn (string $value): string => trim($value))
                ->filter()
                ->reject(fn (string $value): bool => preg_match('/^gr\d+_\d+(?::\d+)?$/', $value) === 1)
                ->values();

            $selected->push($projectGroupToken);

            foreach ($homeGeofenceIds as $homeGeofenceId) {
                if (preg_match('/^(\d+):(\d+)$/', trim($homeGeofenceId), $matches) === 1) {
                    $selected->push($matches[1].'_'.$matches[2]);
                }
            }

            $parameters['geozones'] = $selected->unique()->implode(',').',';

            if ($removeDurationFilter) {
                unset($parameters['duration']);
            }

            $template['tbl'][$index]['p'] = json_encode($parameters, JSON_THROW_ON_ERROR);
            $matched = true;
        }

        if (! $matched) {
            throw new RuntimeException('The geofence report table was not found. The report snapshot was not changed.');
        }

        return $template;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
