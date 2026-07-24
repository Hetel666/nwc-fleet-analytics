<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DashboardLayoutService
{
    private const CACHE_KEY = 'dashboard:layout:default';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableWidgets(): array
    {
        return collect(config('dashboard.widgets', []))
            ->filter(fn (array $widget): bool => (bool) ($widget['active'] ?? true))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDefaultLayout(): array
    {
        return collect($this->getAvailableWidgets())
            ->map(fn (array $widget, string $key): array => $this->normaliseWidget($key, [
                'order' => $widget['default_order'] ?? 999,
                'width' => $widget['default_width'] ?? 12,
                'visible' => true,
            ]))
            ->sortBy('order')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSavedLayout(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $setting = Setting::query()->where('key', $this->settingKey())->first();

            if (! $setting?->value) {
                return [];
            }

            try {
                $payload = json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                Log::warning('Global dashboard layout JSON is invalid', [
                    'setting_key' => $this->settingKey(),
                    'error' => $exception->getMessage(),
                ]);

                return [];
            }

            return is_array($payload['widgets'] ?? null) ? $payload['widgets'] : [];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getResolvedLayout(): array
    {
        $available = $this->getAvailableWidgets();
        $saved = $this->getSavedLayout();
        $seen = [];
        $resolved = [];

        foreach ($saved as $item) {
            $key = (string) ($item['key'] ?? '');

            if (! isset($available[$key]) || isset($seen[$key])) {
                if ($key !== '') {
                    Log::warning('Unknown or duplicated dashboard widget ignored', ['widget_key' => $key]);
                }

                continue;
            }

            $resolved[] = $this->normaliseWidget($key, $item);
            $seen[$key] = true;
        }

        foreach ($this->getDefaultLayout() as $item) {
            if (! isset($seen[$item['key']])) {
                $resolved[] = $item;
            }
        }

        return collect($resolved)
            ->sortBy('order')
            ->values()
            ->map(fn (array $item, int $index): array => [
                ...$item,
                'order' => ($index + 1) * 10,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     */
    public function saveLayout(array $widgets, User $admin, ?string $ip = null): void
    {
        $oldLayout = $this->getResolvedLayout();
        $newLayout = $this->prepareLayoutForStorage($widgets);

        Setting::query()->updateOrCreate(
            ['key' => $this->settingKey()],
            [
                'value' => json_encode([
                    'version' => 1,
                    'updated_by' => $admin->id,
                    'widgets' => $newLayout,
                ], JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
            ]
        );

        Cache::forget(self::CACHE_KEY);

        Log::info('Global dashboard layout updated', [
            'updated_by' => $admin->id,
            'updated_by_email' => $admin->email,
            'ip' => $ip,
            'old_layout' => $oldLayout,
            'new_layout' => $newLayout,
        ]);
    }

    public function resetToDefault(User $admin, ?string $ip = null): void
    {
        $oldLayout = $this->getResolvedLayout();

        Setting::query()->where('key', $this->settingKey())->delete();
        Cache::forget(self::CACHE_KEY);

        Log::info('Global dashboard layout reset', [
            'updated_by' => $admin->id,
            'updated_by_email' => $admin->email,
            'ip' => $ip,
            'old_layout' => $oldLayout,
            'new_layout' => $this->getDefaultLayout(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<int, array<string, mixed>>
     */
    private function prepareLayoutForStorage(array $widgets): array
    {
        $available = $this->getAvailableWidgets();
        $seen = [];
        $prepared = [];

        foreach ($widgets as $index => $item) {
            $key = (string) ($item['key'] ?? '');

            if (! isset($available[$key])) {
                throw ValidationException::withMessages([
                    'widgets' => "Unknown dashboard widget: {$key}",
                ]);
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'widgets' => "Duplicated dashboard widget: {$key}",
                ]);
            }

            $prepared[] = $this->normaliseWidget($key, [
                'order' => (($index + 1) * 10),
                'width' => $item['width'] ?? null,
                'title' => $item['title'] ?? null,
                'visible' => $item['visible'] ?? true,
            ]);
            $seen[$key] = true;
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normaliseWidget(string $key, array $item): array
    {
        $widget = Arr::get($this->getAvailableWidgets(), $key, []);
        $width = (int) ($item['width'] ?? $widget['default_width'] ?? 12);

        if (! in_array($width, [4, 5, 6, 7, 12], true)) {
            throw ValidationException::withMessages([
                'widgets' => "Invalid dashboard widget width: {$width}",
            ]);
        }

        return [
            'key' => $key,
            'order' => (int) ($item['order'] ?? $widget['default_order'] ?? 999),
            'width' => $width,
            'column_class' => (string) ($widget['column_class'] ?? 'col-12'),
            'title' => $this->normaliseTitle($item['title'] ?? null),
            'visible' => $this->normaliseVisible($item['visible'] ?? true),
        ];
    }

    private function normaliseTitle(mixed $title): ?string
    {
        $title = trim((string) $title);

        return $title === '' ? null : mb_substr($title, 0, 120);
    }

    private function normaliseVisible(mixed $visible): bool
    {
        return filter_var($visible, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function settingKey(): string
    {
        return (string) config('dashboard.layout_setting_key', 'dashboard.layout.default');
    }
}
