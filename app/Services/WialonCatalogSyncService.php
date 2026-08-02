<?php

namespace App\Services;

use App\Jobs\SyncWialonCatalogJob;
use App\Models\Equipment;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Models\WialonCatalogSyncItem;
use App\Models\WialonCatalogSyncRun;
use App\Models\WialonGeofence;
use App\Models\WialonGeofenceGroup;
use App\Models\WialonGeofenceGroupMember;
use App\Models\WialonReportTemplate;
use App\Models\WialonResource;
use App\Models\WialonUnit;
use App\Models\WialonUnitGroup;
use App\Models\WialonUnitGroupMember;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WialonCatalogSyncService
{
    public const SECTION_RESOURCES = 'resources';

    public const SECTION_UNIT_GROUPS = 'unit_groups';

    public const SECTION_UNITS = 'units';

    public const SECTION_GEOFENCE_GROUPS = 'geofence_groups';

    public const SECTION_GEOFENCES = 'geofences';

    public const SECTION_REPORT_TEMPLATES = 'report_templates';

    public function __construct(private WialonService $wialon) {}

    /**
     * @param  array<int, string>|null  $sections
     */
    public function queue(?array $sections, string $syncType = 'manual', ?User $user = null): WialonCatalogSyncRun
    {
        $sections = $this->normalizeSections($sections);

        $activeRun = WialonCatalogSyncRun::query()
            ->whereIn('status', [
                WialonCatalogSyncRun::STATUS_QUEUED,
                WialonCatalogSyncRun::STATUS_RUNNING,
                WialonCatalogSyncRun::STATUS_RETRYING,
            ])
            ->latest()
            ->first();

        if ($activeRun instanceof WialonCatalogSyncRun) {
            $this->dispatch($activeRun);

            return $activeRun->refresh();
        }

        $run = WialonCatalogSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'sync_type' => $syncType,
            'sections_json' => $sections,
            'status' => WialonCatalogSyncRun::STATUS_QUEUED,
            'started_by' => $user?->id,
            'last_heartbeat_at' => now(config('app.timezone')),
        ]);

        $this->dispatch($run);

        return $run->refresh();
    }

    public function dispatch(WialonCatalogSyncRun $run): void
    {
        SyncWialonCatalogJob::dispatch($run->id)
            ->onConnection((string) config('wialon_catalog.connection', config('queue.default', 'database')))
            ->onQueue((string) config('wialon_catalog.queue', 'wialon-catalog'))
            ->afterCommit();
    }

    public function sync(WialonCatalogSyncRun $run): array
    {
        $lock = Cache::lock('wialon-catalog-sync', (int) config('wialon_catalog.lock_seconds', 1800));

        if (! $lock->get()) {
            return ['status' => 'locked'];
        }

        $started = microtime(true);
        $sections = $this->normalizeSections($run->sections_json ?? []);
        $fullSync = $this->isFullSync($sections);
        $seen = [];
        $summary = [
            'added' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'errors' => 0,
        ];

        try {
            $run->forceFill([
                'status' => WialonCatalogSyncRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?: now(config('app.timezone')),
                'completed_at' => null,
                'last_error' => null,
                'last_heartbeat_at' => now(config('app.timezone')),
            ])->save();

            $resources = null;

            foreach ($sections as $section) {
                try {
                    if ($this->sectionUsesResourcesSnapshot($section)) {
                        $resources ??= $this->wialon->getReportResources();
                    }

                    $sectionResult = match ($section) {
                        self::SECTION_RESOURCES => $this->syncResources($resources ?? [], $run),
                        self::SECTION_UNIT_GROUPS => $this->syncUnitGroups($run),
                        self::SECTION_UNITS => $this->syncUnits($run),
                        self::SECTION_GEOFENCE_GROUPS => $this->syncGeofenceGroups($resources ?? [], $run),
                        self::SECTION_GEOFENCES => $this->syncGeofences($resources ?? [], $run),
                        self::SECTION_REPORT_TEMPLATES => $this->syncReportTemplates($resources ?? [], $run),
                        default => throw new RuntimeException("Unsupported Wialon catalog section: {$section}"),
                    };

                    $seen[$section] = $sectionResult['seen'] ?? [];
                    $summary['added'] += (int) ($sectionResult['added'] ?? 0);
                    $summary['updated'] += (int) ($sectionResult['updated'] ?? 0);
                } catch (Throwable $exception) {
                    $summary['errors']++;
                    $this->recordItem($run, $section, 'section', null, $section, 'error', 'failed', $exception->getMessage());
                }

                $run->forceFill(['last_heartbeat_at' => now(config('app.timezone'))])->save();
            }

            if ($fullSync && $summary['errors'] === 0) {
                $summary['deactivated'] = $this->deactivateMissing($seen, $run);
            }

            $status = $summary['errors'] > 0
                ? WialonCatalogSyncRun::STATUS_COMPLETED_WITH_ERRORS
                : WialonCatalogSyncRun::STATUS_COMPLETED;

            $run->forceFill([
                'status' => $status,
                'added_count' => $summary['added'],
                'updated_count' => $summary['updated'],
                'deactivated_count' => $summary['deactivated'],
                'error_count' => $summary['errors'],
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'completed_at' => now(config('app.timezone')),
                'last_heartbeat_at' => now(config('app.timezone')),
            ])->save();

            return ['status' => $status, ...$summary];
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => WialonCatalogSyncRun::STATUS_FAILED,
                'error_count' => $summary['errors'] + 1,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'last_error' => $this->maskSecretText($exception->getMessage()),
                'completed_at' => now(config('app.timezone')),
                'last_heartbeat_at' => now(config('app.timezone')),
            ])->save();

            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }

    public function overview(): array
    {
        $lastRun = WialonCatalogSyncRun::query()
            ->whereIn('status', [WialonCatalogSyncRun::STATUS_COMPLETED, WialonCatalogSyncRun::STATUS_COMPLETED_WITH_ERRORS])
            ->latest('completed_at')
            ->first();

        return [
            'wialon_connection' => config('fleet.wialon.token') ? 'configured' : 'missing_token',
            'counts' => [
                'resources' => WialonResource::query()->where('is_active', true)->count(),
                'unit_groups' => WialonUnitGroup::query()->where('is_active', true)->count(),
                'units' => WialonUnit::query()->where('is_active', true)->count(),
                'geofences' => WialonGeofence::query()->where('is_active', true)->count(),
                'geofence_groups' => WialonGeofenceGroup::query()->where('is_active', true)->count(),
                'report_templates' => WialonReportTemplate::query()->where('is_active', true)->count(),
                'unlinked_unit_groups' => WialonUnitGroup::query()->where('is_active', true)->whereNull('linked_project_id')->count(),
            ],
            'last_successful_sync' => optional($lastRun?->completed_at)->toDateTimeString(),
            'last_sync_duration_ms' => $lastRun?->duration_ms,
            'last_added_count' => $lastRun?->added_count ?? 0,
            'last_updated_count' => $lastRun?->updated_count ?? 0,
            'last_deactivated_count' => $lastRun?->deactivated_count ?? 0,
            'last_error' => $lastRun?->last_error,
        ];
    }

    /**
     * @param  array<int, string>|null  $sections
     * @return array<int, string>
     */
    public function normalizeSections(?array $sections): array
    {
        $available = config('wialon_catalog.sections', []);

        $sections = collect($sections ?? [])
            ->map(fn ($section): string => trim((string) $section))
            ->filter()
            ->values()
            ->all();

        if ($sections === [] || in_array('all', $sections, true)) {
            return array_values($available);
        }

        $filtered = collect($sections)
            ->intersect($available)
            ->values()
            ->all();

        return $filtered === [] ? array_values($available) : $filtered;
    }

    private function syncResources(array $resources, WialonCatalogSyncRun $run): array
    {
        $now = now(config('app.timezone'));
        $result = $this->emptyResult();

        foreach ($resources as $resource) {
            $resourceId = $this->itemId($resource);

            if ($resourceId === '') {
                continue;
            }

            $model = WialonResource::firstOrNew(['wialon_resource_id' => $resourceId]);
            $exists = $model->exists;
            $model->fill([
                'name' => $this->itemName($resource, 'Resource '.$resourceId),
                'account_id' => $this->firstString($resource, ['accountId', 'account_id', 'crt', 'bact']),
                'report_templates_count' => count($this->reportTemplates($resource)),
                'geofences_count' => count($this->geofences($resource)),
                'geofence_groups_count' => count($this->geofenceGroups($resource)),
                'is_active' => true,
                'missing_since' => null,
                'last_seen_at' => $now,
                'last_synced_at' => $now,
                'raw_metadata_json' => $this->sanitizeRaw($resource),
            ])->save();

            $this->countAction($result, $exists);
            $result['seen'][] = $resourceId;
            $this->recordItem($run, self::SECTION_RESOURCES, 'resource', $resourceId, $model->name, $exists ? 'updated' : 'added');
        }

        return $result;
    }

    private function syncUnitGroups(WialonCatalogSyncRun $run): array
    {
        $groups = $this->wialon->getUnitGroups();
        $now = now(config('app.timezone'));
        $projectGroups = ProjectWialonGroup::query()->with('project:id,name')->get()->keyBy('wialon_group_id');
        $result = $this->emptyResult();

        foreach ($groups as $group) {
            $groupId = $this->itemId($group);

            if ($groupId === '') {
                continue;
            }

            $projectGroup = $projectGroups->get($groupId);
            $unitIds = $this->unitIds($group);
            $model = WialonUnitGroup::firstOrNew(['wialon_group_id' => $groupId]);
            $exists = $model->exists;
            $model->fill([
                'name' => $this->itemName($group, 'Group '.$groupId),
                'resource_id' => $this->firstString($group, ['resourceId', 'resource_id', 'rid']),
                'account_id' => $this->firstString($group, ['accountId', 'account_id', 'crt', 'bact']),
                'units_count' => $unitIds->count(),
                'linked_project_id' => $projectGroup?->project_id,
                'ownership_type' => $projectGroup?->ownership_type,
                'is_active' => true,
                'missing_since' => null,
                'last_seen_at' => $now,
                'last_synced_at' => $now,
                'raw_metadata_json' => $this->sanitizeRaw($group),
            ])->save();

            foreach ($unitIds as $unitId) {
                $unit = WialonUnit::query()->where('wialon_unit_id', $unitId)->first();
                WialonUnitGroupMember::updateOrCreate(
                    ['wialon_group_id' => $groupId, 'wialon_unit_item_id' => $unitId],
                    [
                        'wialon_unit_group_id' => $model->id,
                        'wialon_unit_id' => $unit?->id,
                        'last_synced_at' => $now,
                    ]
                );
            }

            $this->countAction($result, $exists);
            $result['seen'][] = $groupId;
            $this->recordItem($run, self::SECTION_UNIT_GROUPS, 'unit_group', $groupId, $model->name, $exists ? 'updated' : 'added');
        }

        return $result;
    }

    private function syncUnits(WialonCatalogSyncRun $run): array
    {
        $units = $this->wialon->getUnits(full: true);
        $now = now(config('app.timezone'));
        $equipment = Equipment::query()
            ->with(['type:id,name', 'project:id,name', 'projectWialonGroup:id,wialon_group_id,ownership_type'])
            ->get()
            ->keyBy('wialon_unit_id');
        $result = $this->emptyResult();

        foreach ($units as $unit) {
            $unitId = $this->itemId($unit);

            if ($unitId === '') {
                continue;
            }

            $localEquipment = $equipment->get($unitId);
            $model = WialonUnit::firstOrNew(['wialon_unit_id' => $unitId]);
            $exists = $model->exists;
            $model->fill([
                'name' => $this->itemName($unit, 'Unit '.$unitId),
                'equipment_type_name' => $localEquipment?->type?->name ?? $this->equipmentTypeName($unit),
                'ownership_type' => $localEquipment?->ownership_type,
                'unique_id' => $this->unitUniqueId($unit),
                'imei' => $this->unitImei($unit),
                'linked_project_id' => $localEquipment?->project_id,
                'local_equipment_id' => $localEquipment?->id,
                'is_active' => true,
                'missing_since' => null,
                'last_seen_at' => $now,
                'last_synced_at' => $now,
                'raw_metadata_json' => $this->sanitizeRaw($unit),
            ])->save();

            WialonUnitGroupMember::query()
                ->where('wialon_unit_item_id', $unitId)
                ->update(['wialon_unit_id' => $model->id, 'last_synced_at' => $now]);

            $this->countAction($result, $exists);
            $result['seen'][] = $unitId;
            $this->recordItem($run, self::SECTION_UNITS, 'unit', $unitId, $model->name, $exists ? 'updated' : 'added');
        }

        return $result;
    }

    private function syncGeofenceGroups(array $resources, WialonCatalogSyncRun $run): array
    {
        $now = now(config('app.timezone'));
        $result = $this->emptyResult();
        $foundAnyGroup = false;

        foreach ($resources as $resource) {
            $resourceId = $this->itemId($resource);
            $resourceName = $this->itemName($resource, 'Resource '.$resourceId);

            foreach ($this->geofenceGroups($resource) as $group) {
                $groupId = $this->itemId($group);

                if ($resourceId === '' || $groupId === '') {
                    continue;
                }

                $foundAnyGroup = true;
                $zoneIds = $this->geofenceGroupZoneIds($group);
                $model = WialonGeofenceGroup::firstOrNew([
                    'resource_id' => $resourceId,
                    'wialon_geofence_group_id' => $groupId,
                ]);
                $exists = $model->exists;
                $model->fill([
                    'name' => $this->itemName($group, 'Geofence group '.$groupId),
                    'resource_name' => $resourceName,
                    'geofences_count' => count($zoneIds),
                    'is_active' => true,
                    'missing_since' => null,
                    'last_seen_at' => $now,
                    'last_synced_at' => $now,
                    'raw_metadata_json' => $this->sanitizeRaw($group),
                ])->save();

                foreach ($zoneIds as $zoneId) {
                    $geofence = WialonGeofence::query()
                        ->where('resource_id', $resourceId)
                        ->where('wialon_geofence_id', $zoneId)
                        ->first();
                    WialonGeofenceGroupMember::updateOrCreate(
                        [
                            'resource_id' => $resourceId,
                            'wialon_geofence_group_item_id' => $groupId,
                            'wialon_geofence_item_id' => $zoneId,
                        ],
                        [
                            'wialon_geofence_group_id' => $model->id,
                            'wialon_geofence_id' => $geofence?->id,
                            'last_synced_at' => $now,
                        ]
                    );
                }

                $this->countAction($result, $exists);
                $result['seen'][] = $resourceId.':'.$groupId;
                $this->recordItem($run, self::SECTION_GEOFENCE_GROUPS, 'geofence_group', $resourceId.':'.$groupId, $model->name, $exists ? 'updated' : 'added');
            }
        }

        if (! $foundAnyGroup) {
            $this->recordItem(
                $run,
                self::SECTION_GEOFENCE_GROUPS,
                'geofence_group',
                null,
                'Wialon API response',
                'skipped',
                'completed',
                'No separate geofence group collection was present in the current Wialon resource payload.'
            );
        }

        return $result;
    }

    private function syncGeofences(array $resources, WialonCatalogSyncRun $run): array
    {
        $now = now(config('app.timezone'));
        $localGeofences = Geofence::query()->with('project:id,name')->get();
        $result = $this->emptyResult();

        foreach ($resources as $resource) {
            $resourceId = $this->itemId($resource);
            $resourceName = $this->itemName($resource, 'Resource '.$resourceId);

            foreach ($this->geofences($resource) as $zone) {
                $zoneId = $this->itemId($zone);

                if ($resourceId === '' || $zoneId === '') {
                    continue;
                }

                $localGeofence = $this->matchingLocalGeofence($localGeofences, $resourceId, $zoneId);
                $model = WialonGeofence::firstOrNew([
                    'resource_id' => $resourceId,
                    'wialon_geofence_id' => $zoneId,
                ]);
                $exists = $model->exists;
                $model->fill([
                    'name' => $this->itemName($zone, 'Geofence '.$zoneId),
                    'resource_name' => $resourceName,
                    'geofence_group_id' => $this->firstString($zone, ['groupId', 'group_id', 'gid']),
                    'zone_type' => $this->zoneType($zone),
                    'area' => $this->decimalValue($zone, ['area', 'ar', 's']),
                    'perimeter' => $this->decimalValue($zone, ['perimeter', 'p']),
                    'color' => $this->firstString($zone, ['color', 'c']),
                    'linked_project_id' => $localGeofence?->project_id,
                    'local_geofence_id' => $localGeofence?->id,
                    'is_home_geofence' => $localGeofence instanceof Geofence,
                    'is_active' => true,
                    'missing_since' => null,
                    'last_seen_at' => $now,
                    'last_synced_at' => $now,
                    'raw_geometry_json' => $this->sanitizeRaw($this->zoneGeometry($zone)),
                    'raw_metadata_json' => $this->sanitizeRaw($zone),
                ])->save();

                WialonGeofenceGroupMember::query()
                    ->where('resource_id', $resourceId)
                    ->where('wialon_geofence_item_id', $zoneId)
                    ->update(['wialon_geofence_id' => $model->id, 'last_synced_at' => $now]);

                $this->countAction($result, $exists);
                $result['seen'][] = $resourceId.':'.$zoneId;
                $this->recordItem($run, self::SECTION_GEOFENCES, 'geofence', $resourceId.':'.$zoneId, $model->name, $exists ? 'updated' : 'added');
            }
        }

        return $result;
    }

    private function syncReportTemplates(array $resources, WialonCatalogSyncRun $run): array
    {
        $now = now(config('app.timezone'));
        $usedTemplates = $this->usedReportTemplates();
        $result = $this->emptyResult();

        foreach ($resources as $resource) {
            $resourceId = $this->itemId($resource);
            $resourceName = $this->itemName($resource, 'Resource '.$resourceId);

            foreach ($this->reportTemplates($resource) as $template) {
                $templateId = $this->itemId($template);

                if ($resourceId === '' || $templateId === '') {
                    continue;
                }

                $name = $this->itemName($template, 'Report template '.$templateId);
                $usedBy = $usedTemplates[$this->normalizeName($name)] ?? [];
                $model = WialonReportTemplate::firstOrNew([
                    'resource_id' => $resourceId,
                    'wialon_template_id' => $templateId,
                ]);
                $exists = $model->exists;
                $model->fill([
                    'name' => $name,
                    'resource_name' => $resourceName,
                    'report_type' => $this->firstString($template, ['ct', 'reportType', 'type']),
                    'tables_json' => $this->templateTables($template),
                    'used_by_modules_json' => $usedBy,
                    'usage_status' => $usedBy === [] ? WialonReportTemplate::STATUS_UNUSED : WialonReportTemplate::STATUS_USED,
                    'is_active' => true,
                    'missing_since' => null,
                    'last_seen_at' => $now,
                    'last_synced_at' => $now,
                    'raw_metadata_json' => $this->sanitizeRaw($template),
                ])->save();

                $this->countAction($result, $exists);
                $result['seen'][] = $resourceId.':'.$templateId;
                $this->recordItem($run, self::SECTION_REPORT_TEMPLATES, 'report_template', $resourceId.':'.$templateId, $model->name, $exists ? 'updated' : 'added');
            }
        }

        return $result;
    }

    private function deactivateMissing(array $seen, WialonCatalogSyncRun $run): int
    {
        $now = now(config('app.timezone'));
        $count = 0;

        $count += $this->deactivateWhereMissing(WialonResource::query(), 'wialon_resource_id', $seen[self::SECTION_RESOURCES] ?? [], $now, $run, self::SECTION_RESOURCES, 'resource');
        $count += $this->deactivateWhereMissing(WialonUnitGroup::query(), 'wialon_group_id', $seen[self::SECTION_UNIT_GROUPS] ?? [], $now, $run, self::SECTION_UNIT_GROUPS, 'unit_group');
        $count += $this->deactivateWhereMissing(WialonUnit::query(), 'wialon_unit_id', $seen[self::SECTION_UNITS] ?? [], $now, $run, self::SECTION_UNITS, 'unit');

        $count += $this->deactivateCompoundMissing(
            WialonGeofenceGroup::query(),
            ['resource_id', 'wialon_geofence_group_id'],
            $seen[self::SECTION_GEOFENCE_GROUPS] ?? [],
            $now,
            $run,
            self::SECTION_GEOFENCE_GROUPS,
            'geofence_group'
        );
        $count += $this->deactivateCompoundMissing(
            WialonGeofence::query(),
            ['resource_id', 'wialon_geofence_id'],
            $seen[self::SECTION_GEOFENCES] ?? [],
            $now,
            $run,
            self::SECTION_GEOFENCES,
            'geofence'
        );
        $count += $this->deactivateCompoundMissing(
            WialonReportTemplate::query(),
            ['resource_id', 'wialon_template_id'],
            $seen[self::SECTION_REPORT_TEMPLATES] ?? [],
            $now,
            $run,
            self::SECTION_REPORT_TEMPLATES,
            'report_template'
        );

        return $count;
    }

    private function deactivateWhereMissing($query, string $column, array $seen, Carbon $now, WialonCatalogSyncRun $run, string $section, string $type): int
    {
        if ($seen === []) {
            return 0;
        }

        $models = $query->where('is_active', true)->whereNotIn($column, $seen)->get();

        foreach ($models as $model) {
            $model->forceFill([
                'is_active' => false,
                'missing_since' => $model->missing_since ?: $now,
                'last_synced_at' => $now,
            ])->save();

            $this->recordItem($run, $section, $type, (string) $model->{$column}, $model->name ?? null, 'deactivated');
        }

        return $models->count();
    }

    private function deactivateCompoundMissing($query, array $columns, array $seen, Carbon $now, WialonCatalogSyncRun $run, string $section, string $type): int
    {
        if ($seen === []) {
            return 0;
        }

        $models = $query->where('is_active', true)->get()
            ->reject(fn ($model): bool => in_array($model->{$columns[0]}.':'.$model->{$columns[1]}, $seen, true));

        foreach ($models as $model) {
            $model->forceFill([
                'is_active' => false,
                'missing_since' => $model->missing_since ?: $now,
                'last_synced_at' => $now,
            ])->save();

            $this->recordItem($run, $section, $type, $model->{$columns[0]}.':'.$model->{$columns[1]}, $model->name ?? null, 'deactivated');
        }

        return $models->count();
    }

    private function recordItem(
        WialonCatalogSyncRun $run,
        string $section,
        string $type,
        ?string $wialonId,
        ?string $name,
        string $action,
        string $status = 'completed',
        ?string $error = null,
        ?array $metadata = null
    ): void {
        WialonCatalogSyncItem::query()->create([
            'wialon_catalog_sync_run_id' => $run->id,
            'section' => $section,
            'item_type' => $type,
            'wialon_id' => $wialonId,
            'name' => $name,
            'action' => $action,
            'status' => $status,
            'error' => $error ? $this->maskSecretText($error) : null,
            'metadata_json' => $metadata ? $this->sanitizeRaw($metadata) : null,
        ]);
    }

    private function matchingLocalGeofence(Collection $geofences, string $resourceId, string $zoneId): ?Geofence
    {
        $stableId = $resourceId.':'.$zoneId;

        return $geofences->first(function (Geofence $geofence) use ($stableId, $zoneId): bool {
            return in_array((string) $geofence->wialon_geofence_id, [$stableId, $zoneId], true);
        });
    }

    private function itemId(array $item): string
    {
        foreach (['id', 'i', 'itemId', 'uid'] as $key) {
            if (isset($item[$key]) && (string) $item[$key] !== '') {
                return (string) $item[$key];
            }
        }

        return '';
    }

    private function itemName(array $item, string $fallback): string
    {
        foreach (['nm', 'name', 'n'] as $key) {
            if (isset($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }

        return $fallback;
    }

    private function firstString(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }

        return null;
    }

    private function decimalValue(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (float) $item[$key];
            }
        }

        return null;
    }

    private function zoneType(array $zone): ?string
    {
        $type = $this->firstString($zone, ['type', 'zone_type', 't']);

        return match ((string) $type) {
            '1' => 'line',
            '2' => 'polygon',
            '3' => 'circle',
            default => $type ?: null,
        };
    }

    private function zoneGeometry(array $zone): array
    {
        return Arr::only($zone, ['p', 'points', 'geometry', 'b', 'bounds', 'ct', 'w']);
    }

    private function sectionUsesResourcesSnapshot(string $section): bool
    {
        return in_array($section, [
            self::SECTION_RESOURCES,
            self::SECTION_GEOFENCE_GROUPS,
            self::SECTION_GEOFENCES,
            self::SECTION_REPORT_TEMPLATES,
        ], true);
    }

    private function isFullSync(array $sections): bool
    {
        return collect($sections)->sort()->values()->all()
            === collect(config('wialon_catalog.sections', []))->sort()->values()->all();
    }

    private function emptyResult(): array
    {
        return ['added' => 0, 'updated' => 0, 'seen' => []];
    }

    private function countAction(array &$result, bool $exists): void
    {
        $result[$exists ? 'updated' : 'added']++;
    }

    private function reportTemplates(array $resource): array
    {
        return $this->normalizeCollection($resource['rep'] ?? $resource['reports'] ?? $resource['reportTemplates'] ?? $resource['templates'] ?? []);
    }

    private function geofences(array $resource): array
    {
        return $this->normalizeCollection($resource['zl'] ?? $resource['zones'] ?? $resource['geofences'] ?? []);
    }

    private function geofenceGroups(array $resource): array
    {
        return $this->normalizeCollection($resource['zg'] ?? $resource['zoneGroups'] ?? $resource['geofenceGroups'] ?? $resource['gzl'] ?? []);
    }

    private function normalizeCollection(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function ($item, $key): ?array {
                if (! is_array($item)) {
                    return null;
                }

                if (! isset($item['id']) && is_string($key) && $key !== '') {
                    $item['id'] = $key;
                }

                return $item;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function unitIds(array $group): Collection
    {
        return collect($group['u'] ?? $group['units'] ?? $group['unitIds'] ?? [])
            ->map(fn ($unit): string => is_array($unit) ? (string) ($unit['id'] ?? $unit['i'] ?? '') : (string) $unit)
            ->filter()
            ->values();
    }

    private function geofenceGroupZoneIds(array $group): array
    {
        return collect($group['zns'] ?? $group['zones'] ?? $group['zl'] ?? $group['z'] ?? $group['items'] ?? [])
            ->map(fn ($zone): string => is_array($zone) ? (string) ($zone['id'] ?? $zone['i'] ?? '') : (string) $zone)
            ->filter()
            ->values()
            ->all();
    }

    private function templateTables(array $template): array
    {
        return $this->normalizeCollection($template['tbl'] ?? $template['tables'] ?? []);
    }

    private function usedReportTemplates(): array
    {
        return collect(config('wialon_catalog.used_report_templates', []))
            ->mapWithKeys(fn (array $modules, string $name): array => [$this->normalizeName($name) => array_values($modules)])
            ->all();
    }

    private function normalizeName(string $name): string
    {
        return Str::of($name)->squish()->lower()->toString();
    }

    private function equipmentTypeName(array $unit): ?string
    {
        return $this->profileField($unit, [
            'vehicle_class',
            'vehicle class',
            'equipment_type',
            'equipment type',
            'type',
            'texnika növü',
        ]);
    }

    private function unitUniqueId(array $unit): ?string
    {
        return $this->firstString($unit, ['uid', 'unique_id', 'uniqueId'])
            ?? $this->profileField($unit, ['unique id', 'unique_id', 'uid']);
    }

    private function unitImei(array $unit): ?string
    {
        return $this->firstString($unit, ['imei'])
            ?? $this->profileField($unit, ['imei', 'device imei']);
    }

    private function profileField(array $unit, array $names): ?string
    {
        $lookup = array_flip(array_map(fn (string $name): string => mb_strtolower($name), $names));

        foreach (['pflds', 'flds', 'aflds'] as $fieldSet) {
            foreach (($unit[$fieldSet] ?? []) as $field) {
                $name = mb_strtolower((string) ($field['n'] ?? ''));

                if (isset($lookup[$name])) {
                    $value = trim((string) ($field['v'] ?? ''));

                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }

    private function sanitizeRaw(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $keyText = mb_strtolower((string) $key);
            if (str_contains($keyText, 'token')
                || str_contains($keyText, 'sid')
                || str_contains($keyText, 'password')
                || str_contains($keyText, 'authorization')) {
                $sanitized[$key] = '[masked]';

                continue;
            }

            $sanitized[$key] = is_array($item) ? $this->sanitizeRaw($item) : $item;
        }

        return $sanitized;
    }

    private function maskSecretText(string $text): string
    {
        return preg_replace(
            '/(token|sid|password|authorization)(["\']?\s*[:=]\s*)[^,\s}]+/i',
            '$1$2[masked]',
            $text
        ) ?? $text;
    }
}
