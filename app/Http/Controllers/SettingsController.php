<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $settings = Setting::query()->pluck('value', 'key');

        return view('settings.edit', [
            'settings' => $settings,
            'wialonTokenConfigured' => filled(config('fleet.wialon.token')),
            'syncIntervalOptions' => $this->syncIntervalOptions(),
            'syncStatusRows' => $this->syncStatusRows(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wialon_resource_id' => ['nullable', 'string', 'max:100'],
            'wialon_report_template_id' => ['nullable', 'string', 'max:100'],
            'geofence_min_exit_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'auto_sync_enabled' => ['nullable', 'boolean'],
            'auto_sync_units_enabled' => ['nullable', 'boolean'],
            'auto_sync_units_interval_minutes' => ['required', 'integer', 'in:30,60,180,360,720,1440'],
            'auto_sync_geofences_enabled' => ['nullable', 'boolean'],
            'auto_sync_geofences_interval_minutes' => ['required', 'integer', 'in:360,720,1440,10080'],
            'auto_sync_daily_enabled' => ['nullable', 'boolean'],
            'auto_sync_daily_interval_minutes' => ['required', 'integer', 'in:60,180,360,720,1440'],
            'auto_sync_daily_recent_days' => ['required', 'integer', 'min:1', 'max:7'],
        ]);

        foreach ([
            'auto_sync_enabled',
            'auto_sync_units_enabled',
            'auto_sync_geofences_enabled',
            'auto_sync_daily_enabled',
        ] as $key) {
            $data[$key] = $request->boolean($key) ? '1' : '0';
        }

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'is_secret' => false]);
        }

        return back()->with('status', __('app.saved'));
    }

    public function syncUnits(): RedirectResponse
    {
        $code = Artisan::call('fleet:sync-units');
        $output = trim(Artisan::output());

        $this->storeSyncResult('units', $code === 0, $output);

        return back()->with('status', $output ?: __('app.sync_started'));
    }

    public function syncGeofences(): RedirectResponse
    {
        $code = Artisan::call('fleet:sync-geofences');
        $output = trim(Artisan::output());

        $this->storeSyncResult('geofences', $code === 0, $output);

        return back()->with('status', $output ?: __('app.sync_started'));
    }

    private function storeSyncResult(string $name, bool $success, string $message): void
    {
        $values = [
            "auto_sync_{$name}_last_run_at" => now(config('app.timezone'))->toDateTimeString(),
            "auto_sync_{$name}_last_status" => $success ? 'success' : 'failed',
            "auto_sync_{$name}_last_message" => mb_substr($message, 0, 1000),
        ];

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'is_secret' => false]);
        }
    }

    private function syncIntervalOptions(): array
    {
        return [
            30 => '30 deqiqe',
            60 => '1 saat',
            180 => '3 saat',
            360 => '6 saat',
            720 => '12 saat',
            1440 => '1 gun',
            10080 => '1 hefte',
        ];
    }

    private function syncStatusRows(): array
    {
        return [
            ['label' => 'Texnikalar', 'key' => 'units'],
            ['label' => 'Geofence', 'key' => 'geofences'],
            ['label' => 'Gundelik statistika', 'key' => 'daily'],
            ['label' => 'Top 20 motosaat', 'key' => 'top20'],
            ['label' => 'Effektivlik shift hesabatı', 'key' => 'shift'],
        ];
    }
}
