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
        return view('settings.edit', [
            'settings' => Setting::query()->pluck('value', 'key'),
            'wialonTokenConfigured' => filled(config('fleet.wialon.token')),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wialon_resource_id' => ['nullable', 'string', 'max:100'],
            'wialon_report_template_id' => ['nullable', 'string', 'max:100'],
            'geofence_min_exit_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'is_secret' => false]);
        }

        return back()->with('status', __('app.saved'));
    }

    public function syncUnits(): RedirectResponse
    {
        Artisan::call('fleet:sync-units');

        return back()->with('status', trim(Artisan::output()) ?: __('app.sync_started'));
    }
}
