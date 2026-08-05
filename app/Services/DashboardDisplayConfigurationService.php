<?php

namespace App\Services;

use App\Models\DashboardConfigurationAuditLog;
use App\Models\DashboardStatusVisibilitySetting;
use App\Models\DashboardVisibilitySetting;
use App\Models\Equipment;
use App\Models\User;
use App\Support\EfficiencyStatus;
use App\Support\MonthlyEfficiencyStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DashboardDisplayConfigurationService
{
    public function getConfiguration(bool $includeHidden = true): array
    {
        $configuration = $this->cachedConfiguration();

        if ($includeHidden) {
            return $configuration;
        }

        return [
            'dashboards' => collect($configuration['dashboards'])
                ->filter(fn (array $dashboard): bool => (bool) $dashboard['is_visible'])
                ->values()
                ->all(),
            'statuses' => collect($configuration['statuses'])
                ->map(fn (array $statuses): array => collect($statuses)
                    ->filter(fn (array $status): bool => (bool) $status['is_visible'])
                    ->values()
                    ->all())
                ->all(),
        ];
    }

    public function getPublicConfiguration(): array
    {
        $configuration = $this->getConfiguration(includeHidden: false);

        return [
            'dashboards' => collect($configuration['dashboards'])
                ->map(fn (array $dashboard): array => [
                    'code' => $dashboard['code'],
                    'section_code' => $dashboard['section_code'],
                    'is_visible' => true,
                    'display_order' => $dashboard['display_order'],
                    'layout_widget' => $dashboard['layout_widget'],
                ])
                ->values()
                ->all(),
            'statuses' => collect($configuration['statuses'])
                ->map(fn (array $statuses): array => collect($statuses)
                    ->map(fn (array $status): array => [
                        'dashboard_type' => $status['dashboard_type'],
                        'status_code' => $status['status_code'],
                        'is_visible' => true,
                        'display_order' => $status['display_order'],
                    ])
                    ->values()
                    ->all())
                ->all(),
        ];
    }

    public function dashboardRows(): array
    {
        return $this->cachedConfiguration()['dashboards'];
    }

    public function statusRows(): array
    {
        return collect($this->cachedConfiguration()['statuses'])
            ->flatMap(fn (array $rows): array => $rows)
            ->values()
            ->all();
    }

    public function auditRows(int $limit = 100): array
    {
        if (! $this->auditTableReady()) {
            return [];
        }

        return DashboardConfigurationAuditLog::query()
            ->with('admin:id,name,email')
            ->latest('created_at')
            ->limit(max(1, min(300, $limit)))
            ->get()
            ->map(fn (DashboardConfigurationAuditLog $log): array => [
                'id' => $log->id,
                'created_at' => optional($log->created_at)->toDateTimeString(),
                'admin_user_id' => $log->admin_user_id,
                'admin_name' => $log->admin?->name,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_code' => $log->entity_code,
                'old_value' => $log->old_value_json,
                'new_value' => $log->new_value_json,
                'ip_address' => $log->ip_address,
            ])
            ->all();
    }

    public function isDashboardVisible(string $dashboardCode): bool
    {
        $row = collect($this->dashboardRows())->firstWhere('code', $dashboardCode);

        return $row === null ? false : (bool) $row['is_visible'];
    }

    public function isWidgetVisible(string $widgetKey): bool
    {
        $dashboardCode = $this->dashboardCodeForWidget($widgetKey);

        return $dashboardCode === null || $this->isDashboardVisible($dashboardCode);
    }

    public function hiddenWidgetKeys(): array
    {
        return collect($this->dashboardRows())
            ->reject(fn (array $dashboard): bool => (bool) $dashboard['is_visible'])
            ->pluck('layout_widget')
            ->filter()
            ->values()
            ->all();
    }

    public function dashboardOrder(string $dashboardCode, int $default = 999): int
    {
        $row = collect($this->dashboardRows())->firstWhere('code', $dashboardCode);

        return $row === null ? $default : (int) $row['display_order'];
    }

    public function dashboardOrderForWidget(string $widgetKey, int $default = 999): int
    {
        $dashboardCode = $this->dashboardCodeForWidget($widgetKey);

        return $dashboardCode === null ? $default : $this->dashboardOrder($dashboardCode, $default);
    }

    public function dashboardCodeForExportBlock(string $block): ?string
    {
        return collect($this->dashboardRegistry())
            ->first(fn (array $dashboard): bool => in_array($block, $dashboard['export_blocks'] ?? [], true))['code'] ?? null;
    }

    public function visibleStatusCodes(string $dashboardType): array
    {
        $rows = $this->cachedConfiguration()['statuses'][$dashboardType] ?? [];

        if ($rows === [] && array_key_exists($dashboardType, config('dashboard_visibility.status_types', []))) {
            $rows = collect($this->statusRegistry($dashboardType))
                ->map(fn (array $status): array => [
                    ...$status,
                    'is_visible' => true,
                ])
                ->all();
        }

        return collect($rows)
            ->filter(fn (array $status): bool => (bool) $status['is_visible'])
            ->sortBy('display_order')
            ->pluck('status_code')
            ->values()
            ->all();
    }

    public function isStatusVisible(string $dashboardType, ?string $statusCode): bool
    {
        $statusCode = $this->canonicalStatus($statusCode);

        if ($statusCode === null) {
            return true;
        }

        return in_array($statusCode, $this->visibleStatusCodes($dashboardType), true);
    }

    public function applyVisibleStatusesToFilters(array $filters, string $dashboardType): array
    {
        $status = $this->canonicalStatus($filters['status'] ?? $filters['work_category'] ?? $filters['day_status'] ?? null);

        if ($status !== null && ! $this->isStatusVisible($dashboardType, $status)) {
            abort(403, 'Bu Dashboard statusu administrator terefinden gizledilib.');
        }

        return [
            ...$filters,
            'visible_statuses' => $this->visibleStatusCodes($dashboardType),
        ];
    }

    public function filterSummaryRows(array $rows, string $dashboardType): array
    {
        $visible = $this->visibleStatusCodes($dashboardType);

        return collect($rows)
            ->filter(fn (array $row): bool => in_array((string) ($row['status'] ?? ''), $visible, true))
            ->values()
            ->all();
    }

    public function assertDashboardVisibleForOwnership(array $filters, string $nwcCode, string $rentalCode): void
    {
        $ownership = mb_strtolower((string) ($filters['ownership_type'] ?? $filters['ownership'] ?? ''));
        $codes = match ($ownership) {
            mb_strtolower(Equipment::OWNERSHIP_NWC), 'nwc' => [$nwcCode],
            mb_strtolower(Equipment::OWNERSHIP_ICARE), 'icare' => [$rentalCode],
            default => [$nwcCode, $rentalCode],
        };

        $visible = collect($codes)->contains(fn (string $code): bool => $this->isDashboardVisible($code));

        abort_unless($visible, 403, 'Bu Dashboard administrator terefinden gizledilib.');
    }

    public function updateDashboard(string $dashboardCode, array $data, User $admin, ?string $ip = null): array
    {
        $this->ensureSettingsTablesReady();
        $dashboard = $this->dashboardDefinition($dashboardCode);
        $old = $this->dashboardRow($dashboardCode);

        $setting = DashboardVisibilitySetting::query()->updateOrCreate(
            ['dashboard_code' => $dashboardCode],
            [
                'section_code' => $dashboard['section'],
                'is_visible' => array_key_exists('is_visible', $data) ? (bool) $data['is_visible'] : (bool) $old['is_visible'],
                'display_order' => (int) ($data['display_order'] ?? $old['display_order']),
                'updated_by' => $admin->id,
            ]
        );

        $this->flushCache();
        $new = $this->dashboardRow($dashboardCode);
        $this->audit($this->dashboardAuditAction($old, $new), 'dashboard', $dashboardCode, $old, $new, $admin, $ip);

        return $new;
    }

    public function updateStatus(string $dashboardType, string $statusCode, array $data, User $admin, ?string $ip = null): array
    {
        $this->ensureSettingsTablesReady();
        $this->dashboardTypeDefinition($dashboardType);
        $statusCode = $this->statusDefinition($dashboardType, $statusCode)['status_code'];
        $old = $this->statusRow($dashboardType, $statusCode);

        DashboardStatusVisibilitySetting::query()->updateOrCreate(
            [
                'dashboard_type' => $dashboardType,
                'status_code' => $statusCode,
            ],
            [
                'is_visible' => array_key_exists('is_visible', $data) ? (bool) $data['is_visible'] : (bool) $old['is_visible'],
                'updated_by' => $admin->id,
            ]
        );

        $this->flushCache();
        $new = $this->statusRow($dashboardType, $statusCode);
        $this->audit($this->statusAuditAction($old, $new), 'status', $dashboardType.':'.$statusCode, $old, $new, $admin, $ip);

        return $new;
    }

    public function updateOrder(array $dashboards, User $admin, ?string $ip = null): array
    {
        $this->ensureSettingsTablesReady();
        $old = $this->dashboardRows();

        DB::transaction(function () use ($dashboards, $admin): void {
            foreach ($dashboards as $item) {
                $code = (string) ($item['code'] ?? '');
                $definition = $this->dashboardDefinition($code);

                DashboardVisibilitySetting::query()->updateOrCreate(
                    ['dashboard_code' => $code],
                    [
                        'section_code' => $definition['section'],
                        'is_visible' => (bool) ($this->dashboardRow($code)['is_visible'] ?? true),
                        'display_order' => (int) ($item['display_order'] ?? $definition['default_order']),
                        'updated_by' => $admin->id,
                    ]
                );
            }
        });

        $this->flushCache();
        $new = $this->dashboardRows();
        $this->audit('order_changed', 'dashboard', 'all', $old, $new, $admin, $ip);

        return $new;
    }

    public function reset(User $admin, ?string $ip = null): array
    {
        $this->ensureSettingsTablesReady();
        $old = $this->getConfiguration();

        DB::transaction(function (): void {
            DashboardVisibilitySetting::query()->delete();
            DashboardStatusVisibilitySetting::query()->delete();
        });

        $this->flushCache();
        $new = $this->getConfiguration();
        $this->audit('configuration_reset', 'configuration', 'all', $old, $new, $admin, $ip);

        return $new;
    }

    private function cachedConfiguration(): array
    {
        return Cache::rememberForever($this->cacheKey(), fn (): array => $this->buildConfiguration());
    }

    private function buildConfiguration(): array
    {
        $dashboardSettings = $this->visibilityTableReady()
            ? DashboardVisibilitySetting::query()->get()->keyBy('dashboard_code')
            : collect();
        $statusSettings = $this->statusTableReady()
            ? DashboardStatusVisibilitySetting::query()->get()->keyBy(fn (DashboardStatusVisibilitySetting $setting): string => $setting->dashboard_type.':'.$setting->status_code)
            : collect();

        $dashboards = collect($this->dashboardRegistry())
            ->map(function (array $dashboard) use ($dashboardSettings): array {
                $setting = $dashboardSettings->get($dashboard['code']);

                return [
                    'code' => $dashboard['code'],
                    'title_az' => $dashboard['title_az'],
                    'section_code' => $dashboard['section'],
                    'is_visible' => $setting ? (bool) $setting->is_visible : true,
                    'display_order' => $setting ? (int) $setting->display_order : (int) $dashboard['default_order'],
                    'layout_widget' => $dashboard['layout_widget'],
                    'updated_by' => $setting?->updated_by,
                    'updated_at' => optional($setting?->updated_at)->toDateTimeString(),
                ];
            })
            ->sortBy([['section_code', 'asc'], ['display_order', 'asc'], ['code', 'asc']])
            ->values()
            ->all();

        $statuses = collect($this->statusTypeRegistry())
            ->mapWithKeys(function (array $type) use ($statusSettings): array {
                $rows = collect($this->statusRegistry($type['dashboard_type']))
                    ->map(function (array $status) use ($type, $statusSettings): array {
                        $setting = $statusSettings->get($type['dashboard_type'].':'.$status['status_code']);

                        return [
                            'dashboard_type' => $type['dashboard_type'],
                            'dashboard_type_title_az' => $type['title_az'],
                            'status_code' => $status['status_code'],
                            'title_az' => $status['title_az'],
                            'is_visible' => $setting ? (bool) $setting->is_visible : true,
                            'display_order' => (int) $status['default_order'],
                            'updated_by' => $setting?->updated_by,
                            'updated_at' => optional($setting?->updated_at)->toDateTimeString(),
                        ];
                    })
                    ->sortBy('display_order')
                    ->values()
                    ->all();

                return [$type['dashboard_type'] => $rows];
            })
            ->all();

        return [
            'dashboards' => $dashboards,
            'statuses' => $statuses,
        ];
    }

    private function dashboardRegistry(): array
    {
        return collect(config('dashboard_visibility.dashboards', []))
            ->map(fn (array $definition, string $code): array => [
                'code' => $code,
                'title_az' => (string) ($definition['title_az'] ?? $code),
                'section' => (string) ($definition['section'] ?? 'overview'),
                'default_order' => (int) ($definition['default_order'] ?? 999),
                'layout_widget' => $definition['layout_widget'] ?? null,
                'export_blocks' => $definition['export_blocks'] ?? [],
            ])
            ->all();
    }

    private function statusTypeRegistry(): array
    {
        return collect(config('dashboard_visibility.status_types', []))
            ->map(fn (array $definition, string $type): array => [
                'dashboard_type' => $type,
                'title_az' => (string) ($definition['title_az'] ?? $type),
            ])
            ->all();
    }

    private function statusRegistry(?string $dashboardType = null): array
    {
        $statuses = config('dashboard_visibility.statuses_by_type.'.$dashboardType)
            ?? config('dashboard_visibility.statuses', []);

        return collect($statuses)
            ->map(fn (array $definition, string $status): array => [
                'status_code' => $status,
                'title_az' => (string) ($definition['title_az'] ?? $status),
                'default_order' => (int) ($definition['default_order'] ?? 999),
            ])
            ->all();
    }

    private function dashboardDefinition(string $dashboardCode): array
    {
        $definition = collect($this->dashboardRegistry())->firstWhere('code', $dashboardCode);

        if ($definition === null) {
            throw ValidationException::withMessages(['dashboard_code' => "Unknown dashboard code: {$dashboardCode}"]);
        }

        return $definition;
    }

    private function dashboardTypeDefinition(string $dashboardType): array
    {
        $definition = collect($this->statusTypeRegistry())->firstWhere('dashboard_type', $dashboardType);

        if ($definition === null) {
            throw ValidationException::withMessages(['dashboard_type' => "Unknown dashboard type: {$dashboardType}"]);
        }

        return $definition;
    }

    private function statusDefinition(string $dashboardType, string $statusCode): array
    {
        $statusCode = $this->canonicalStatus($statusCode) ?? $statusCode;
        $definition = collect($this->statusRegistry($dashboardType))->firstWhere('status_code', $statusCode);

        if ($definition === null) {
            throw ValidationException::withMessages(['status_code' => "Unknown status code: {$statusCode}"]);
        }

        return $definition;
    }

    private function dashboardRow(string $dashboardCode): array
    {
        $this->flushCache();

        return collect($this->cachedConfiguration()['dashboards'])->firstWhere('code', $dashboardCode)
            ?? throw ValidationException::withMessages(['dashboard_code' => "Unknown dashboard code: {$dashboardCode}"]);
    }

    private function statusRow(string $dashboardType, string $statusCode): array
    {
        $this->flushCache();

        return collect($this->cachedConfiguration()['statuses'][$dashboardType] ?? [])->firstWhere('status_code', $statusCode)
            ?? throw ValidationException::withMessages(['status_code' => "Unknown status code: {$statusCode}"]);
    }

    private function dashboardCodeForWidget(string $widgetKey): ?string
    {
        $dashboard = collect($this->dashboardRegistry())
            ->first(fn (array $dashboard): bool => ($dashboard['layout_widget'] ?? null) === $widgetKey);

        return $dashboard['code'] ?? null;
    }

    private function canonicalStatus(?string $status): ?string
    {
        return match ($status) {
            '0_1', 'less_than_1', 'less_than_1_hour' => EfficiencyStatus::ZERO_TO_ONE,
            '1_7', 'from_1_to_7', 'less_than_7_hours' => EfficiencyStatus::ONE_TO_SEVEN,
            '7_10', 'from_7_to_10', 'between_7_and_10_hours' => EfficiencyStatus::SEVEN_TO_TEN,
            'over_10', 'over_10_hours', 'over_10_day_hours' => EfficiencyStatus::OVER_TEN,
            'no_data' => EfficiencyStatus::NO_DATA,
            MonthlyEfficiencyStatus::CRITICAL_LOW, 'kritik_asagi', 'critical' => MonthlyEfficiencyStatus::CRITICAL_LOW,
            MonthlyEfficiencyStatus::LOW, 'asagi' => MonthlyEfficiencyStatus::LOW,
            MonthlyEfficiencyStatus::NORMAL => MonthlyEfficiencyStatus::NORMAL,
            default => null,
        };
    }

    private function dashboardAuditAction(array $old, array $new): string
    {
        if ((bool) ($old['is_visible'] ?? true) !== (bool) ($new['is_visible'] ?? true)) {
            return (bool) ($new['is_visible'] ?? true) ? 'dashboard_shown' : 'dashboard_hidden';
        }

        if ((int) ($old['display_order'] ?? 0) !== (int) ($new['display_order'] ?? 0)) {
            return 'order_changed';
        }

        return 'dashboard_updated';
    }

    private function statusAuditAction(array $old, array $new): string
    {
        if ((bool) ($old['is_visible'] ?? true) !== (bool) ($new['is_visible'] ?? true)) {
            return (bool) ($new['is_visible'] ?? true) ? 'status_shown' : 'status_hidden';
        }

        return 'status_updated';
    }

    private function audit(string $action, string $entityType, string $entityCode, array $old, array $new, User $admin, ?string $ip): void
    {
        if (! $this->auditTableReady()) {
            return;
        }

        DashboardConfigurationAuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_code' => $entityCode,
            'old_value_json' => $old,
            'new_value_json' => $new,
            'ip_address' => $ip,
        ]);
    }

    private function flushCache(): void
    {
        Cache::forget($this->baseCacheKey());
        Cache::forget($this->cacheKey());
    }

    private function baseCacheKey(): string
    {
        return (string) config('dashboard_visibility.cache_key', 'dashboard:global-display-configuration');
    }

    private function cacheKey(): string
    {
        $signature = md5(json_encode([
            'dashboards' => config('dashboard_visibility.dashboards', []),
            'status_types' => config('dashboard_visibility.status_types', []),
            'statuses' => config('dashboard_visibility.statuses', []),
            'statuses_by_type' => config('dashboard_visibility.statuses_by_type', []),
        ]));

        return $this->baseCacheKey().':'.$signature;
    }

    private function ensureSettingsTablesReady(): void
    {
        if (! $this->visibilityTableReady() || ! $this->statusTableReady()) {
            throw ValidationException::withMessages([
                'dashboard_visibility' => 'Dashboard visibility tables are not migrated.',
            ]);
        }
    }

    private function visibilityTableReady(): bool
    {
        return Schema::hasTable('dashboard_visibility_settings');
    }

    private function statusTableReady(): bool
    {
        return Schema::hasTable('dashboard_status_visibility_settings');
    }

    private function auditTableReady(): bool
    {
        return Schema::hasTable('dashboard_configuration_audit_logs');
    }
}
