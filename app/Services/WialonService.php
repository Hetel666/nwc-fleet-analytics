<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WialonService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('fleet.wialon.base_url'), '/');
    }

    public function loginByToken(): string
    {
        $token = config('fleet.wialon.token');

        if (! $token) {
            throw new RuntimeException('Wialon token is not configured.');
        }

        $response = $this->request('token/login', ['token' => $token], false);
        $sid = $response['eid'] ?? null;

        if (! $sid) {
            throw new RuntimeException('Wialon login did not return a session id.');
        }

        Cache::put($this->sessionCacheKey(), $sid, now()->addMinutes((int) config('fleet.wialon.session_cache_minutes', 30)));

        return $sid;
    }

    public function getSessionId(): string
    {
        return Cache::remember($this->sessionCacheKey(), now()->addMinutes((int) config('fleet.wialon.session_cache_minutes', 30)), function (): string {
            return $this->loginByToken();
        });
    }

    public function logout(): void
    {
        $sid = Cache::pull($this->sessionCacheKey());

        if ($sid) {
            $this->request('core/logout', [], $sid);
        }
    }

    public function getUnits(): array
    {
        $response = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_unit',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => 1 | 1024 | 4194304,
            'from' => 0,
            'to' => 0,
        ]);

        return $response['items'] ?? [];
    }

    public function getUnit(int|string $unitId): array
    {
        return $this->request('core/search_item', [
            'id' => (int) $unitId,
            'flags' => 1 | 1024 | 4194304,
        ]);
    }

    public function getUnitGroups(array $groupIds = []): array
    {
        $response = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_unit_group',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => -1,
            'from' => 0,
            'to' => 0,
        ]);

        $items = $response['items'] ?? [];
        if ($groupIds === []) {
            return $items;
        }

        $allowed = array_flip(array_map('strval', $groupIds));

        return array_values(array_filter($items, fn (array $item): bool => isset($allowed[(string) ($item['id'] ?? '')])));
    }

    public function getUnitLastPosition(int|string $unitId): ?array
    {
        $unit = $this->getUnit($unitId);
        $position = $unit['item']['pos'] ?? $unit['pos'] ?? null;

        if (! is_array($position)) {
            return null;
        }

        return [
            'lat' => $position['y'] ?? null,
            'lng' => $position['x'] ?? null,
            'speed' => $position['s'] ?? null,
            'time' => isset($position['t']) ? Carbon::createFromTimestamp((int) $position['t'])->toDateTimeString() : null,
        ];
    }

    public function getMessages(int|string $unitId, int $from, int $to): array
    {
        $response = $this->request('messages/load_interval', [
            'itemId' => (int) $unitId,
            'timeFrom' => $from,
            'timeTo' => $to,
            'flags' => 1,
            'flagsMask' => 65281,
            'loadCount' => 0,
        ]);

        return $response['messages'] ?? [];
    }

    public function getGeofences(): array
    {
        $resources = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_resource',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => 1 | 4096,
            'from' => 0,
            'to' => 0,
        ]);

        $zones = [];

        foreach ($resources['items'] ?? [] as $resource) {
            foreach (($resource['zl'] ?? []) as $zoneId => $zone) {
                $zones[] = [
                    'resource_id' => $resource['id'] ?? null,
                    'id' => $zone['id'] ?? $zoneId,
                    'name' => $zone['n'] ?? $zone['name'] ?? 'Geofence',
                    'raw' => $zone,
                ];
            }
        }

        return $zones;
    }

    public function getReportData(string $resourceId, string $templateId, int $from, int $to, array $objects = []): array
    {
        return $this->request('report/exec_report', [
            'reportResourceId' => (int) $resourceId,
            'reportTemplateId' => (int) $templateId,
            'reportObjectId' => $objects[0] ?? 0,
            'reportObjectSecId' => 0,
            'interval' => [
                'from' => $from,
                'to' => $to,
                'flags' => 0,
            ],
        ]);
    }

    public function calculateUnitDailyData(int|string $unitId, Carbon $date, string $mode = 'engine_hours'): array
    {
        $messages = $this->getMessages(
            $unitId,
            $date->copy()->startOfDay()->timestamp,
            $date->copy()->endOfDay()->timestamp
        );

        if ($messages === []) {
            return [
                'worked_hours' => 0,
                'distance_km' => 0,
                'first_message_at' => null,
                'last_message_at' => null,
                'calculation_source' => 'wialon_messages',
                'calculation_status' => 'no_messages',
            ];
        }

        $workedSeconds = 0;
        $distanceKm = 0.0;
        $lastMessage = null;
        $lastPosition = null;
        $engineStart = null;
        $firstTime = null;
        $lastTime = null;
        $engineHoursStart = null;
        $engineHoursEnd = null;

        foreach ($messages as $message) {
            $time = isset($message['t']) ? (int) $message['t'] : null;
            $params = $message['p'] ?? [];

            if (! $time) {
                continue;
            }

            $firstTime ??= $time;
            $lastTime = $time;

            $engineHours = $this->extractNumericParam($params, ['engine_hours', 'eh', 'moto_hours', 'enginehours']);
            if ($engineHours !== null) {
                $engineHoursStart ??= $engineHours;
                $engineHoursEnd = $engineHours;
            }

            $engineOn = $this->extractIgnition($params);
            if ($mode !== 'mileage' && $engineOn && $engineStart === null) {
                $engineStart = $time;
            }
            if ($mode !== 'mileage' && ! $engineOn && $engineStart !== null) {
                $workedSeconds += max(0, $time - $engineStart);
                $engineStart = null;
            }

            $position = $this->extractPosition($message);
            if ($position && $lastPosition) {
                $distanceKm += $this->distanceKm($lastPosition, $position);
            }
            $lastPosition = $position ?: $lastPosition;
            $lastMessage = $message;
        }

        if ($engineStart !== null && $lastTime !== null) {
            $workedSeconds += max(0, $lastTime - $engineStart);
        }

        if ($engineHoursStart !== null && $engineHoursEnd !== null && $engineHoursEnd >= $engineHoursStart) {
            $workedHours = $engineHoursEnd - $engineHoursStart;
        } elseif ($mode === 'mileage') {
            $workedHours = 0;
        } else {
            $workedHours = $workedSeconds / 3600;
        }

        return [
            'worked_hours' => round($workedHours, 2),
            'distance_km' => round($distanceKm, 2),
            'first_message_at' => $firstTime ? Carbon::createFromTimestamp($firstTime) : null,
            'last_message_at' => $lastTime ? Carbon::createFromTimestamp($lastTime) : null,
            'last_position' => $lastMessage ? $this->extractPosition($lastMessage) : null,
            'calculation_source' => 'wialon_messages',
            'calculation_status' => 'ok',
        ];
    }

    private function request(string $svc, array $params = [], string|bool|null $sid = null): array
    {
        $payload = [
            'svc' => $svc,
            'params' => json_encode($params, JSON_THROW_ON_ERROR),
        ];

        if ($sid === null) {
            $payload['sid'] = $this->getSessionId();
        } elseif (is_string($sid)) {
            $payload['sid'] = $sid;
        }

        $response = Http::timeout((int) config('fleet.wialon.timeout', 30))
            ->retry(2, 300)
            ->asForm()
            ->post($this->baseUrl.'/wialon/ajax.html', $payload);

        if ($response->failed()) {
            Log::warning('Wialon HTTP request failed', [
                'svc' => $svc,
                'status' => $response->status(),
            ]);

            throw new RuntimeException("Wialon HTTP error for {$svc}: {$response->status()}");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException("Wialon returned invalid JSON for {$svc}.");
        }

        if (array_key_exists('error', $data)) {
            Cache::forget($this->sessionCacheKey());
            Log::warning('Wialon API error', [
                'svc' => $svc,
                'error' => $data['error'],
            ]);

            throw new RuntimeException("Wialon API error {$data['error']} for {$svc}.");
        }

        return $data;
    }

    private function extractIgnition(array $params): bool
    {
        foreach (['ign', 'ignition', 'acc', 'engine', 'engine_on'] as $key) {
            if (array_key_exists($key, $params)) {
                return (bool) (is_array($params[$key]) ? ($params[$key]['v'] ?? false) : $params[$key]);
            }
        }

        return false;
    }

    private function extractNumericParam(array $params, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                $value = is_array($params[$key]) ? ($params[$key]['v'] ?? null) : $params[$key];

                return is_numeric($value) ? (float) $value : null;
            }
        }

        return null;
    }

    private function extractPosition(array $message): ?array
    {
        $position = $message['pos'] ?? null;

        if (! is_array($position) || ! isset($position['x'], $position['y'])) {
            return null;
        }

        return [
            'lat' => (float) $position['y'],
            'lng' => (float) $position['x'],
            'speed' => $position['s'] ?? null,
            'time' => isset($message['t']) ? Carbon::createFromTimestamp((int) $message['t'])->toDateTimeString() : null,
        ];
    }

    private function distanceKm(array $from, array $to): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($to['lat'] - $from['lat']);
        $lngDelta = deg2rad($to['lng'] - $from['lng']);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function sessionCacheKey(): string
    {
        return 'wialon.session_id';
    }
}
