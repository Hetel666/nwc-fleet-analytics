<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DashboardAnalyticsController extends Controller
{
    public function index(): View
    {
        $updateSections = config('dashboard_analytics.update_sections', []);

        return view('admin.dashboard-analytics.index', [
            'widgets' => collect(config('dashboard_analytics.widgets', []))
                ->map(fn (array $widget): array => $widget + [
                    'update_section' => $this->updateSectionForWidget((string) ($widget['key'] ?? '')),
                ])
                ->all(),
            'sharedBindings' => config('dashboard_analytics.shared_bindings', []),
            'updateSections' => $updateSections,
        ]);
    }

    private function updateSectionForWidget(string $key): string
    {
        return match ($key) {
            'average-engine-hours', 'average-mileage', 'utilization-trend' => 'daily_averages',
            'least-working', 'most-working' => 'top_working_units',
            'geofence-analysis' => 'geofence_outside',
            'geofence-violations-report' => 'geofence_violations',
            'project-work-categories-nwc', 'project-work-categories-icare' => 'efficiency',
            default => 'static_fleet',
        };
    }
}
