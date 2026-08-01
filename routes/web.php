<?php

use App\Http\Controllers\Admin\DashboardAnalyticsController;
use App\Http\Controllers\Admin\HistoricalRecalculationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardDrilldownController;
use App\Http\Controllers\DashboardExportController;
use App\Http\Controllers\DashboardLayoutController;
use App\Http\Controllers\DashboardOwnershipExportController;
use App\Http\Controllers\DashboardTopWorkingUnitsExportController;
use App\Http\Controllers\EfficiencyDashboardController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentTypeController;
use App\Http\Controllers\GeofenceController;
use App\Http\Controllers\GeofenceViolationsDashboardController;
use App\Http\Controllers\GeofenceViolationsDrilldownController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show'])->name('health');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1');
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::redirect('/', '/dashboard');
    Route::get('/language/{locale}', [LanguageController::class, 'update'])->name('language.update');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/geofence-violations', GeofenceViolationsDashboardController::class)
        ->name('geofence-violations.index');
    Route::get('/dashboard/geofence-violations/drilldown', GeofenceViolationsDrilldownController::class)
        ->name('dashboard.geofence-violations.drilldown');
    Route::get('/dashboard/tabs/{tab}', [DashboardController::class, 'tab'])->name('dashboard.tabs.show');
    Route::get('/dashboard/drilldown/units', [DashboardDrilldownController::class, 'index'])->name('dashboard.drilldown.units');
    Route::get('/dashboard/drilldown/units/export', [DashboardDrilldownController::class, 'export'])->name('dashboard.drilldown.units.export');
    Route::put('/dashboard/layout', [DashboardLayoutController::class, 'update'])->middleware('admin')->name('dashboard.layout.update');
    Route::delete('/dashboard/layout', [DashboardLayoutController::class, 'destroy'])->middleware('admin')->name('dashboard.layout.destroy');
    Route::get('/dashboard/ownership/export', DashboardOwnershipExportController::class)->name('dashboard.ownership.export');
    Route::get('/dashboard/top-working-units/export', DashboardTopWorkingUnitsExportController::class)->name('dashboard.top-working-units.export');
    Route::get('/dashboard/export', [DashboardExportController::class, 'create'])->name('dashboard.export');
    Route::get('/dashboard/exports/{export}/status', [DashboardExportController::class, 'status'])->name('dashboard.exports.status');
    Route::get('/dashboard/exports/{export}/download', [DashboardExportController::class, 'download'])->name('dashboard.exports.download');
    Route::get('/api/dashboard/efficiency/summary', [EfficiencyDashboardController::class, 'summary'])->name('api.dashboard.efficiency.summary');
    Route::get('/api/dashboard/efficiency/projects', [EfficiencyDashboardController::class, 'projects'])->name('api.dashboard.efficiency.projects');
    Route::get('/api/dashboard/efficiency/units', [EfficiencyDashboardController::class, 'units'])->name('api.dashboard.efficiency.units');
    Route::get('/api/dashboard/efficiency/export', [EfficiencyDashboardController::class, 'export'])->name('api.dashboard.efficiency.export');
    Route::get('/projects/{project}/dashboard', [ProjectDashboardController::class, 'show'])->name('projects.dashboard');

    Route::resource('projects', ProjectController::class)->except(['show'])->middleware('admin');
    Route::resource('equipment-types', EquipmentTypeController::class)->except(['show'])->middleware('admin');
    Route::resource('equipment', EquipmentController::class)->except(['show'])->middleware('admin');
    Route::resource('geofences', GeofenceController::class)->except(['show'])->middleware('admin');
    Route::resource('users', UserController::class)->except(['show'])->middleware('admin');

    Route::get('/admin/dashboard-analytics', [DashboardAnalyticsController::class, 'index'])
        ->middleware('admin')
        ->name('admin.dashboard-analytics.index');

    Route::prefix('admin/historical-recalculations')
        ->name('admin.historical-recalculations.')
        ->middleware('admin')
        ->group(function (): void {
            Route::get('/', [HistoricalRecalculationController::class, 'index'])->name('index');
            Route::post('/preview', [HistoricalRecalculationController::class, 'preview'])->name('preview');
            Route::post('/', [HistoricalRecalculationController::class, 'store'])->name('store');
            Route::get('/{historicalRecalculation:uuid}', [HistoricalRecalculationController::class, 'show'])->name('show');
            Route::get('/{historicalRecalculation:uuid}/status', [HistoricalRecalculationController::class, 'status'])->name('status');
            Route::post('/{historicalRecalculation:uuid}/cancel', [HistoricalRecalculationController::class, 'cancel'])->name('cancel');
            Route::post('/{historicalRecalculation:uuid}/retry', [HistoricalRecalculationController::class, 'retry'])->name('retry');
        });

    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('admin')->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('admin')->name('settings.update');
    Route::post('/settings/sync-units', [SettingsController::class, 'syncUnits'])->middleware('admin')->name('settings.sync-units');
    Route::post('/settings/sync-geofences', [SettingsController::class, 'syncGeofences'])->middleware('admin')->name('settings.sync-geofences');
});
