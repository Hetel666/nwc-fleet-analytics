<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WialonService
{
    private string $baseUrl;

    private ?int $requestTimeoutOverride = null;

    private ?float $requestDeadlineAt = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('fleet.wialon.base_url'), '/');
    }

    public function loginByToken(bool $cacheSession = true): string
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

        if ($cacheSession) {
            Cache::put($this->sessionCacheKey(), $sid, now()->addMinutes((int) config('fleet.wialon.session_cache_minutes', 30)));
        }

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
            $this->logoutSession($sid);
        }
    }

    public function logoutSession(string $sid): void
    {
        $this->request('core/logout', [], $sid);
    }

    public function getUnits(bool $full = false): array
    {
        $response = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_unit',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => $full ? -1 : 1 | 1024 | 4194304,
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
        if ($groupIds !== []) {
            $groups = [];

            foreach (array_values(array_unique(array_map('strval', $groupIds))) as $groupId) {
                $response = $this->request('core/search_item', [
                    'id' => (int) $groupId,
                    'flags' => -1,
                ]);

                $item = $response['item'] ?? null;

                if (is_array($item) && $item !== []) {
                    $groups[] = $item;
                }
            }

            return $groups;
        }

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

        return $response['items'] ?? [];
    }

    public function getResource(int|string $resourceId): array
    {
        $response = $this->request('core/search_item', [
            'id' => (int) $resourceId,
            'flags' => -1,
        ]);

        return $response['item'] ?? $response;
    }

    /**
     * @param  array<int, int|string>  $zoneIds
     * @return array<int, array<string, mixed>>
     */
    public function getGeofenceZonesByIds(int|string $resourceId, array $zoneIds): array
    {
        $zoneIds = array_values(array_filter($zoneIds, fn (int|string|null $id): bool => filled($id)));

        if ($zoneIds === []) {
            return [];
        }

        return $this->request('resource/get_zone_data', [
            'itemId' => (int) $resourceId,
            'col' => $zoneIds,
            'flags' => 1 | 2 | 4 | 8 | 16,
        ]);
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
        return $this->request('report/exec_report', $this->reportExecutionPayload([
            'reportResourceId' => (int) $resourceId,
            'reportTemplateId' => (int) $templateId,
            'reportObjectId' => $objects[0] ?? 0,
            'reportObjectSecId' => 0,
            'interval' => [
                'from' => $from,
                'to' => $to,
                'flags' => 0,
            ],
        ]));
    }

    public function getReportRows(
        int|string $resourceId,
        int|string $templateId,
        int|string $objectId,
        int $from,
        int $to,
        int $tableIndex = 0,
        int $chunkSize = 500,
        int $intervalFlags = 0,
        bool $remoteExec = false,
        ?int $requestTimeout = null
    ): array {
        $previousTimeout = $this->requestTimeoutOverride;
        $previousDeadline = $this->requestDeadlineAt;
        $this->requestTimeoutOverride = $requestTimeout;
        $this->requestDeadlineAt = $requestTimeout !== null ? microtime(true) + max(1, $requestTimeout) : null;

        try {
        $sid = $this->getSessionId();
        $this->cleanupReportResult($sid);

        $payload = $this->reportExecutionPayload([
            'reportResourceId' => (int) $resourceId,
            'reportTemplateId' => (int) $templateId,
            'reportTemplate' => null,
            'reportObjectId' => (int) $objectId,
            'reportObjectSecId' => 0,
            'interval' => [
                'from' => $from,
                'to' => $to,
                'flags' => $intervalFlags,
            ],
        ]);

        if ($remoteExec) {
            $payload['remoteExec'] = 1;
            $payload['reportObjectIdList'] = [];
        }

        $result = $this->request('report/exec_report', $payload, $sid);

        if ($remoteExec) {
            $result = $this->waitForRemoteReportResult($sid);
        }

        $table = $result['reportResult']['tables'][$tableIndex] ?? null;
        $rowCount = (int) ($table['rows'] ?? 0);
        $rows = [];

        for ($indexFrom = 0; $indexFrom < $rowCount; $indexFrom += $chunkSize) {
            $chunk = $this->getResultRowsChunk(
                $sid,
                $tableIndex,
                $indexFrom,
                min($rowCount - 1, $indexFrom + $chunkSize - 1)
            );

            if (is_array($chunk)) {
                $rows = array_merge($rows, $chunk);
            }
        }

        return [
            'result' => $result,
            'table' => $table,
            'rows' => $rows,
        ];
        } finally {
            $this->requestTimeoutOverride = $previousTimeout;
            $this->requestDeadlineAt = $previousDeadline;
        }
    }

    public function getReportTablesRows(
        int|string $resourceId,
        int|string $templateId,
        int|string $objectId,
        int $from,
        int $to,
        int $chunkSize = 500,
        int $intervalFlags = 0,
        bool $remoteExec = false,
        ?int $requestTimeout = null
    ): array {
        $previousTimeout = $this->requestTimeoutOverride;
        $previousDeadline = $this->requestDeadlineAt;
        $this->requestTimeoutOverride = $requestTimeout;
        $this->requestDeadlineAt = $requestTimeout !== null ? microtime(true) + max(1, $requestTimeout) : null;

        try {
        $sid = $this->getSessionId();
        $this->cleanupReportResult($sid);

        $payload = $this->reportExecutionPayload([
            'reportResourceId' => (int) $resourceId,
            'reportTemplateId' => (int) $templateId,
            'reportTemplate' => null,
            'reportObjectId' => (int) $objectId,
            'reportObjectSecId' => 0,
            'interval' => [
                'from' => $from,
                'to' => $to,
                'flags' => $intervalFlags,
            ],
        ]);

        if ($remoteExec) {
            $payload['remoteExec'] = 1;
            $payload['reportObjectIdList'] = [];
        }

        $result = $this->request('report/exec_report', $payload, $sid);

        if ($remoteExec) {
            $result = $this->waitForRemoteReportResult($sid);
        }

        $reportTables = [];

        foreach (($result['reportResult']['tables'] ?? []) as $tableIndex => $table) {
            $rowCount = (int) ($table['rows'] ?? 0);
            $rows = [];

            if ($rowCount > 0) {
                $rows = $this->getSelectedRowsForTable($sid, (int) $tableIndex, $table, $rowCount, $chunkSize);

                if ($rows === []) {
                    for ($indexFrom = 0; $indexFrom < $rowCount; $indexFrom += $chunkSize) {
                        $chunk = $this->getResultRowsChunk(
                            $sid,
                            (int) $tableIndex,
                            $indexFrom,
                            min($rowCount - 1, $indexFrom + $chunkSize - 1)
                        );

                        if (is_array($chunk)) {
                            $rows = array_merge($rows, $chunk);
                        }
                    }
                }
            }

            $reportTables[] = [
                'index' => (int) $tableIndex,
                'table' => $table,
                'rows' => $rows,
            ];
        }

        return [
            'result' => $result,
            'tables' => $reportTables,
        ];
        } finally {
            $this->requestTimeoutOverride = $previousTimeout;
            $this->requestDeadlineAt = $previousDeadline;
        }
    }

    public function getReportResources(): array
    {
        $response = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_resource',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => -1,
            'from' => 0,
            'to' => 0,
        ]);

        return $response['items'] ?? [];
    }

    public function findReportTemplateByName(int|string|null $resourceId, string $templateName): ?array
    {
        $target = $this->normalizeReportTemplateName($templateName);

        if ($target === '') {
            return null;
        }

        $resources = $resourceId !== null && (string) $resourceId !== ''
            ? [$this->getResource($resourceId)]
            : $this->getReportResources();

        foreach ($resources as $resource) {
            $candidates = [];
            $this->collectReportTemplateCandidates($resource, $candidates);

            foreach ($candidates as $candidate) {
                if ($this->normalizeReportTemplateName((string) ($candidate['name'] ?? '')) !== $target) {
                    continue;
                }

                return [
                    ...$candidate,
                    'resource_id' => (int) ($resource['id'] ?? $resourceId ?? 0),
                    'resource_name' => (string) ($resource['nm'] ?? $resource['name'] ?? $resource['n'] ?? ''),
                ];
            }
        }

        return null;
    }

    public function findReportTemplateIdByName(int|string $resourceId, string $templateName): ?int
    {
        return $this->findReportTemplateByName($resourceId, $templateName)['id'] ?? null;
    }

    public function getReportTemplateData(
        int|string $resourceId,
        int|string $templateId,
        ?string $sid = null
    ): array {
        $templates = $this->request('report/get_report_data', [
            'itemId' => (int) $resourceId,
            'col' => [(int) $templateId],
            'flags' => 0,
        ], $sid ?? $this->getSessionId());

        foreach ($templates as $template) {
            if (is_array($template) && (int) ($template['id'] ?? 0) === (int) $templateId) {
                return $template;
            }
        }

        throw new RuntimeException("Wialon report template {$templateId} data was not returned.");
    }

    public function executeReportTemplate(
        int|string $resourceId,
        array $reportTemplate,
        int|string $objectId,
        int $from,
        int $to,
        int $intervalFlags = 0,
        ?string $sid = null,
        bool $remoteExec = false,
        ?int $requestTimeout = null
    ): array {
        $previousTimeout = $this->requestTimeoutOverride;
        $previousDeadline = $this->requestDeadlineAt;
        $this->requestTimeoutOverride = $requestTimeout;
        $this->requestDeadlineAt = $requestTimeout !== null ? microtime(true) + max(1, $requestTimeout) : null;

        try {
            $payload = $this->reportExecutionPayload([
                'reportResourceId' => (int) $resourceId,
                'reportTemplateId' => 0,
                'reportTemplate' => $reportTemplate,
                'reportObjectId' => (int) $objectId,
                'reportObjectSecId' => 0,
                'interval' => [
                    'from' => $from,
                    'to' => $to,
                    'flags' => $intervalFlags,
                ],
            ]);

            if ($remoteExec) {
                $payload['remoteExec'] = 1;
                $payload['reportObjectIdList'] = [];
            }

            $sessionId = $sid ?? $this->getSessionId();
            $result = $this->request('report/exec_report', $payload, $sessionId);

            return $remoteExec ? $this->waitForRemoteReportResult($sessionId) : $result;
        } finally {
            $this->requestTimeoutOverride = $previousTimeout;
            $this->requestDeadlineAt = $previousDeadline;
        }
    }

    public function executeReport(
        int|string $resourceId,
        int|string $templateId,
        int|string $objectId,
        int $from,
        int $to,
        int $intervalFlags = 0,
        ?string $sid = null,
        bool $remoteExec = false,
        ?int $requestTimeout = null
    ): array {
        $previousTimeout = $this->requestTimeoutOverride;
        $previousDeadline = $this->requestDeadlineAt;
        $this->requestTimeoutOverride = $requestTimeout;
        $this->requestDeadlineAt = $requestTimeout !== null ? microtime(true) + max(1, $requestTimeout) : null;

        try {
        $payload = $this->reportExecutionPayload([
            'reportResourceId' => (int) $resourceId,
            'reportTemplateId' => (int) $templateId,
            'reportTemplate' => null,
            'reportObjectId' => (int) $objectId,
            'reportObjectSecId' => 0,
            'interval' => [
                'from' => $from,
                'to' => $to,
                'flags' => $intervalFlags,
            ],
        ]);

        if ($remoteExec) {
            $payload['remoteExec'] = 1;
            $payload['reportObjectIdList'] = [];
        }

        $sessionId = $sid ?? $this->getSessionId();
        $result = $this->request('report/exec_report', $payload, $sessionId);

        return $remoteExec ? $this->waitForRemoteReportResult($sessionId) : $result;
        } finally {
            $this->requestTimeoutOverride = $previousTimeout;
            $this->requestDeadlineAt = $previousDeadline;
        }
    }

    public function selectReportResultRows(int $tableIndex, array $config, ?string $sid = null): array
    {
        $rows = $this->request('report/select_result_rows', [
            'tableIndex' => $tableIndex,
            'config' => $config,
        ], $sid ?? $this->getSessionId());

        return is_array($rows) ? $rows : [];
    }

    public function getReportResultRows(int $tableIndex, int $indexFrom, int $indexTo, ?string $sid = null): array
    {
        $rows = $this->getResultRowsChunk(
            $sid ?? $this->getSessionId(),
            $tableIndex,
            $indexFrom,
            $indexTo,
        );

        return is_array($rows) ? $rows : [];
    }

    public function getReportResultSubrows(int $tableIndex, int $rowIndex, ?string $sid = null): array
    {
        $rows = $this->request('report/get_result_subrows', [
            'tableIndex' => $tableIndex,
            'rowIndex' => $rowIndex,
        ], $sid ?? $this->getSessionId());

        return is_array($rows) ? $rows : [];
    }

    private function getSelectedRowsForTable(string $sid, int $tableIndex, array $table, int $rowCount, int $chunkSize): array
    {
        $tableLevel = (int) ($table['level'] ?? 1);
        $levels = array_values(array_unique([
            max(1, $tableLevel - 1),
            max(0, $tableLevel),
            0,
        ]));

        foreach ($levels as $level) {
            try {
                $rows = $this->selectResultRowsPaged($sid, $tableIndex, $rowCount, $chunkSize, $level, true);

                if ($rows !== []) {
                    return $rows;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        return [];
    }

    private function selectResultRowsPaged(
        string $sid,
        int $tableIndex,
        int $rowCount,
        int $chunkSize,
        int $level,
        bool $unitInfo = false
    ): array {
        $rows = [];

        for ($indexFrom = 0; $indexFrom < $rowCount; $indexFrom += $chunkSize) {
            $data = [
                'from' => $indexFrom,
                'to' => min($rowCount - 1, $indexFrom + $chunkSize - 1),
                'level' => $level,
            ];

            if ($unitInfo) {
                $data['unitInfo'] = 1;
            }

            $chunk = $this->request('report/select_result_rows', [
                'tableIndex' => $tableIndex,
                'config' => [
                    'type' => 'range',
                    'data' => $data,
                ],
            ], $sid);

            if (is_array($chunk)) {
                $rows = array_merge($rows, $chunk);
            }
        }

        return $rows;
    }

    private function collectReportTemplateCandidates(mixed $node, array &$candidates, int|string|null $nodeKey = null): void
    {
        if (! is_array($node)) {
            return;
        }

        $name = $node['n'] ?? $node['name'] ?? $node['nm'] ?? null;
        $id = $node['id'] ?? $node['i'] ?? (is_numeric($nodeKey) ? $nodeKey : null);

        if ($name !== null && $id !== null && $this->looksLikeReportTemplate($node)) {
            $candidates[] = [
                'id' => (int) $id,
                'name' => (string) $name,
                'type' => $node['ct'] ?? $node['bind'] ?? $node['tp'] ?? null,
                'tables' => $node['tbl'] ?? $node['tables'] ?? [],
                'raw_keys' => array_keys($node),
            ];
        }

        foreach ($node as $key => $value) {
            $this->collectReportTemplateCandidates($value, $candidates, $key);
        }
    }

    private function looksLikeReportTemplate(array $node): bool
    {
        foreach (['tbl', 'tables', 'ct', 'p', 'bind'] as $key) {
            if (array_key_exists($key, $node)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeReportTemplateName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    private function getResultRowsChunk(string $sid, int $tableIndex, int $indexFrom, int $indexTo): array
    {
        $attempts = (int) config('fleet.wialon.report_rows_attempts', 6);
        $delayMs = (int) config('fleet.wialon.report_rows_delay_ms', 1000);

        for ($attempt = 0; $attempt < max(1, $attempts); $attempt++) {
            try {
                return $this->request('report/get_result_rows', [
                    'tableIndex' => $tableIndex,
                    'indexFrom' => $indexFrom,
                    'indexTo' => $indexTo,
                ], $sid);
            } catch (RuntimeException $exception) {
                $isTemporaryReportError = str_contains($exception->getMessage(), 'Wialon API error 5 for report/get_result_rows');

                if (! $isTemporaryReportError || $attempt === max(1, $attempts) - 1) {
                    throw $exception;
                }

                usleep(max(100, $delayMs) * 1000);
            }
        }

        return [];
    }

    public function cleanupReportResult(string $sid): void
    {
        try {
            $this->request('report/cleanup_result', [], $sid);
        } catch (Throwable) {
            // Wialon returns an error when there is no previous report result; it is safe to ignore.
        }
    }

    private function waitForRemoteReportResult(string $sid): array
    {
        $attempts = (int) config('fleet.wialon.report_status_attempts', 90);
        $delayMs = (int) config('fleet.wialon.report_status_delay_ms', 1000);

        for ($attempt = 0; $attempt < max(1, $attempts); $attempt++) {
            $this->ensureRequestDeadlineNotExceeded('Wialon remote report did not finish before request timeout.');

            $status = $this->request('report/get_report_status', [], $sid);
            $code = (int) ($status['status'] ?? 0);

            if ($code === 4) {
                return $this->request('report/apply_report_result', [], $sid);
            }

            if (in_array($code, [8, 16], true)) {
                throw new RuntimeException("Wialon remote report failed with status {$code}.");
            }

            $sleepMs = max(100, $delayMs);

            if ($this->requestDeadlineAt !== null) {
                $remainingMs = (int) max(0, floor(($this->requestDeadlineAt - microtime(true)) * 1000));
                $sleepMs = min($sleepMs, max(100, $remainingMs));
            }

            usleep($sleepMs * 1000);
        }

        throw new RuntimeException('Wialon remote report did not finish in time.');
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
                'overtime_hours' => null,
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
        $hasEngineIntervals = false;
        $overtimeSeconds = 0;
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
                $segmentSeconds = max(0, $time - $engineStart);
                $workedSeconds += $segmentSeconds;
                $overtimeSeconds += $this->overtimeSecondsBetween($engineStart, $time);
                $hasEngineIntervals = $hasEngineIntervals || $segmentSeconds > 0;
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
            $segmentSeconds = max(0, $lastTime - $engineStart);
            $workedSeconds += $segmentSeconds;
            $overtimeSeconds += $this->overtimeSecondsBetween($engineStart, $lastTime);
            $hasEngineIntervals = $hasEngineIntervals || $segmentSeconds > 0;
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
            'overtime_hours' => $hasEngineIntervals ? round(min($workedHours, $overtimeSeconds / 3600), 2) : null,
            'distance_km' => round($distanceKm, 2),
            'first_message_at' => $firstTime ? Carbon::createFromTimestamp($firstTime) : null,
            'last_message_at' => $lastTime ? Carbon::createFromTimestamp($lastTime) : null,
            'last_position' => $lastMessage ? $this->extractPosition($lastMessage) : null,
            'calculation_source' => 'wialon_messages',
            'calculation_status' => 'ok',
        ];
    }

    private function overtimeSecondsBetween(int $startTimestamp, int $endTimestamp): int
    {
        if ($endTimestamp <= $startTimestamp) {
            return 0;
        }

        $timezone = config('fleet_efficiency.timezone', config('app.timezone', 'Asia/Baku'));
        $start = Carbon::createFromTimestamp($startTimestamp)->timezone($timezone);
        $end = Carbon::createFromTimestamp($endTimestamp)->timezone($timezone);
        $seconds = 0;

        for ($day = $start->copy()->startOfDay(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            foreach (config('fleet_efficiency.overtime', []) as $window) {
                $windowStart = Carbon::parse($day->toDateString().' '.$window['start'], $timezone);
                $windowEnd = Carbon::parse($day->toDateString().' '.$window['end'], $timezone);

                if (str_ends_with((string) $window['end'], ':59')) {
                    $windowEnd->addSecond();
                }

                $overlapStart = $start->greaterThan($windowStart) ? $start : $windowStart;
                $overlapEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;

                if ($overlapEnd->greaterThan($overlapStart)) {
                    $seconds += (int) floor($overlapStart->diffInSeconds($overlapEnd));
                }
            }
        }

        return $seconds;
    }

    private function request(string $svc, array $params = [], string|bool|null $sid = null): array
    {
        $this->ensureRequestDeadlineNotExceeded("Wialon request timeout exceeded before {$svc}.");

        $payload = [
            'svc' => $svc,
            'params' => json_encode($params, JSON_THROW_ON_ERROR),
        ];

        if ($sid === null) {
            $payload['sid'] = $this->getSessionId();
        } elseif (is_string($sid)) {
            $payload['sid'] = $sid;
        }

        $timeout = $this->requestTimeoutOverride ?? (int) config('fleet.wialon.timeout', 30);

        $http = Http::timeout($timeout);

        if ($this->requestTimeoutOverride === null) {
            $http = $http->retry(2, 300);
        }

        $response = $http
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

        if (array_key_exists('error', $data) && (int) $data['error'] !== 0) {
            Cache::forget($this->sessionCacheKey());

            if ($sid === null) {
                $payload['sid'] = $this->loginByToken();
                $http = Http::timeout($timeout);

                if ($this->requestTimeoutOverride === null) {
                    $http = $http->retry(2, 300);
                }

                $response = $http
                    ->asForm()
                    ->post($this->baseUrl.'/wialon/ajax.html', $payload);

                if (! $response->failed()) {
                    $data = $response->json();

                    if (is_array($data) && (! array_key_exists('error', $data) || (int) $data['error'] === 0)) {
                        return $data;
                    }
                }
            }

            Log::warning('Wialon API error', [
                'svc' => $svc,
                'error' => $data['error'],
            ]);

            throw new RuntimeException("Wialon API error {$data['error']} for {$svc}.");
        }

        return $data;
    }

    /**
     * Wialon evaluates report table time limitations in the timezone supplied
     * to exec_report. Keep this explicit so API reports match the web UI.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function reportExecutionPayload(array $payload): array
    {
        $payload['tzOffset'] = (int) config('fleet.wialon.report_timezone_offset', 134232128);

        $language = trim((string) config('fleet.wialon.report_language', 'ru'));

        if ($language !== '') {
            $payload['lang'] = $language;
        }

        return $payload;
    }

    private function ensureRequestDeadlineNotExceeded(string $message): void
    {
        if ($this->requestDeadlineAt !== null && microtime(true) >= $this->requestDeadlineAt) {
            throw new RuntimeException($message);
        }
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
