<?php

use App\Http\Controllers\Admin\CleanupHistoricalRecalculationQueueController;
use App\Http\Controllers\Admin\DashboardAnalyticsController;
use App\Http\Controllers\Admin\DashboardResyncDryRunController;
use App\Http\Controllers\Admin\DashboardVisibilityController;
use App\Http\Controllers\Admin\HistoricalRecalculationController;
use App\Http\Controllers\Admin\WialonCatalogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardDisplayConfigurationController;
use App\Http\Controllers\DashboardDrilldownController;
use App\Http\Controllers\DashboardExportController;
use App\Http\Controllers\DashboardLayoutController;
use App\Http\Controllers\DashboardOwnershipExportController;
use App\Http\Controllers\DashboardPreferencesController;
use App\Http\Controllers\EfficiencyDashboardController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentTypeController;
use App\Http\Controllers\GeofenceController;
use App\Http\Controllers\GeofenceViolationsDashboardController;
use App\Http\Controllers\GeofenceViolationsDrilldownController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MonthlyEfficiencyDashboardController;
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
    Route::get('/api/dashboard/display-configuration', DashboardDisplayConfigurationController::class)->name('api.dashboard.display-configuration');
    Route::get('/api/user/dashboard-preferences', [DashboardPreferencesController::class, 'show'])->name('api.user.dashboard-preferences.show');
    Route::put('/api/user/dashboard-preferences', [DashboardPreferencesController::class, 'update'])->name('api.user.dashboard-preferences.update');
    Route::delete('/api/user/dashboard-preferences', [DashboardPreferencesController::class, 'destroy'])->name('api.user.dashboard-preferences.destroy');
    Route::get('/geofence-violations', GeofenceViolationsDashboardController::class)
        ->middleware('dashboard.section:geozones')
        ->name('geofence-violations.index');
    Route::get('/geofence-violations/export', [GeofenceViolationsDashboardController::class, 'export'])
        ->middleware('dashboard.section:geozones')
        ->name('geofence-violations.export');
    Route::get('/dashboard/geofence-violations/drilldown', GeofenceViolationsDrilldownController::class)
        ->middleware('dashboard.section:geozones')
        ->name('dashboard.geofence-violations.drilldown');
    Route::get('/dashboard/tabs/{tab}', [DashboardController::class, 'tab'])->middleware('dashboard.section:tab')->name('dashboard.tabs.show');
    Route::get('/dashboard/drilldown/units', [DashboardDrilldownController::class, 'index'])->middleware('dashboard.section:drilldown')->name('dashboard.drilldown.units');
    Route::get('/dashboard/drilldown/units/export', [DashboardDrilldownController::class, 'export'])->middleware('dashboard.section:drilldown')->name('dashboard.drilldown.units.export');
    Route::put('/dashboard/layout', [DashboardLayoutController::class, 'update'])->middleware('admin')->name('dashboard.layout.update');
    Route::delete('/dashboard/layout', [DashboardLayoutController::class, 'destroy'])->middleware('admin')->name('dashboard.layout.destroy');
    Route::get('/dashboard/ownership/export', DashboardOwnershipExportController::class)->middleware('dashboard.section:overview')->name('dashboard.ownership.export');
    Route::get('/dashboard/export', [DashboardExportController::class, 'create'])->middleware('dashboard.section:export')->name('dashboard.export');
    Route::get('/dashboard/exports/{export}/status', [DashboardExportController::class, 'status'])->name('dashboard.exports.status');
    Route::get('/dashboard/exports/{export}/download', [DashboardExportController::class, 'download'])->name('dashboard.exports.download');
    Route::middleware('dashboard.section:efficiency')->group(function (): void {
        Route::get('/api/dashboard/efficiency/summary', [EfficiencyDashboardController::class, 'summary'])->name('api.dashboard.efficiency.summary');
        Route::get('/api/dashboard/efficiency/projects', [EfficiencyDashboardController::class, 'projects'])->name('api.dashboard.efficiency.projects');
        Route::get('/api/dashboard/efficiency/units', [EfficiencyDashboardController::class, 'units'])->name('api.dashboard.efficiency.units');
        Route::get('/api/dashboard/efficiency/export', [EfficiencyDashboardController::class, 'export'])->name('api.dashboard.efficiency.export');
        Route::get('/api/dashboard/monthly-efficiency/summary', [MonthlyEfficiencyDashboardController::class, 'summary'])->name('api.dashboard.monthly-efficiency.summary');
        Route::get('/api/dashboard/monthly-efficiency/projects', [MonthlyEfficiencyDashboardController::class, 'projects'])->name('api.dashboard.monthly-efficiency.projects');
        Route::get('/api/dashboard/monthly-efficiency/units', [MonthlyEfficiencyDashboardController::class, 'units'])->name('api.dashboard.monthly-efficiency.units');
        Route::get('/api/dashboard/monthly-efficiency/objects', [MonthlyEfficiencyDashboardController::class, 'objects'])->name('api.dashboard.monthly-efficiency.objects');
        Route::get('/api/dashboard/monthly-efficiency/object-geofences', [MonthlyEfficiencyDashboardController::class, 'objectGeofences'])->name('api.dashboard.monthly-efficiency.object-geofences');
        Route::get('/api/dashboard/monthly-efficiency/object-geofence-days', [MonthlyEfficiencyDashboardController::class, 'objectGeofenceDays'])->name('api.dashboard.monthly-efficiency.object-geofence-days');
        Route::get('/api/dashboard/monthly-efficiency/export', [MonthlyEfficiencyDashboardController::class, 'export'])->name('api.dashboard.monthly-efficiency.export');
    });
    Route::get('/projects/{project}/dashboard', [ProjectDashboardController::class, 'show'])->name('projects.dashboard');

    Route::resource('projects', ProjectController::class)->except(['show'])->middleware('can:manage-projects');
    Route::resource('equipment-types', EquipmentTypeController::class)->except(['show'])->middleware('admin');
    Route::resource('equipment', EquipmentController::class)->except(['show'])->middleware('admin');
    Route::resource('geofences', GeofenceController::class)->except(['show'])->middleware('admin');
    Route::resource('users', UserController::class)->except(['show'])->middleware('admin');

    Route::get('/admin/dashboard-analytics', [DashboardAnalyticsController::class, 'index'])
        ->middleware('admin')
        ->name('admin.dashboard-analytics.index');

    Route::post('/admin/dashboard-resync/dry-run', DashboardResyncDryRunController::class)
        ->middleware('admin')
        ->name('admin.dashboard-resync.dry-run');

    Route::get('/admin/dashboard-visibility', [DashboardVisibilityController::class, 'index'])
        ->middleware('can:manage-dashboard-visibility')
        ->name('admin.dashboard-visibility.index');

    Route::prefix('api/admin')
        ->name('api.admin.')
        ->middleware('can:manage-dashboard-visibility')
        ->group(function (): void {
            Route::get('/dashboard-visibility', [DashboardVisibilityController::class, 'show'])->name('dashboard-visibility.show');
            Route::put('/dashboard-visibility/{dashboardCode}', [DashboardVisibilityController::class, 'updateDashboard'])->name('dashboard-visibility.update');
            Route::get('/dashboard-status-visibility', [DashboardVisibilityController::class, 'statusVisibility'])->name('dashboard-status-visibility.index');
            Route::put('/dashboard-status-visibility/{dashboardType}/{statusCode}', [DashboardVisibilityController::class, 'updateStatus'])->name('dashboard-status-visibility.update');
            Route::put('/dashboard-order', [DashboardVisibilityController::class, 'updateOrder'])->name('dashboard-order.update');
            Route::post('/dashboard-visibility/reset', [DashboardVisibilityController::class, 'reset'])->name('dashboard-visibility.reset');
            Route::get('/dashboard-visibility/audit-log', [DashboardVisibilityController::class, 'auditLog'])->name('dashboard-visibility.audit-log');
        });

    Route::get('/admin/wialon-catalog', [WialonCatalogController::class, 'index'])
        ->middleware('can:view-wialon-catalog')
        ->name('admin.wialon-catalog.index');

    Route::prefix('api/wialon-catalog')
        ->name('api.wialon-catalog.')
        ->middleware('can:view-wialon-catalog')
        ->group(function (): void {
            Route::get('/overview', [WialonCatalogController::class, 'overview'])->name('overview');
            Route::get('/resources', [WialonCatalogController::class, 'resources'])->name('resources');
            Route::get('/unit-groups', [WialonCatalogController::class, 'unitGroups'])->name('unit-groups');
            Route::get('/units', [WialonCatalogController::class, 'units'])->name('units');
            Route::get('/geofence-groups', [WialonCatalogController::class, 'geofenceGroups'])->name('geofence-groups');
            Route::get('/geofences', [WialonCatalogController::class, 'geofences'])->name('geofences');
            Route::get('/report-templates', [WialonCatalogController::class, 'reportTemplates'])->name('report-templates');
            Route::post('/sync', [WialonCatalogController::class, 'sync'])->middleware('can:sync-wialon-catalog')->name('sync');
            Route::get('/sync-runs', [WialonCatalogController::class, 'syncRuns'])->name('sync-runs');
            Route::get('/sync-runs/{run}', [WialonCatalogController::class, 'syncRun'])->name('sync-runs.show');
        });

    Route::prefix('api/projects')
        ->name('api.projects.')
        ->middleware('can:manage-projects')
        ->group(function (): void {
            Route::get('/wialon-options', [WialonCatalogController::class, 'projectOptions'])->name('wialon-options');
            Route::post('/', [WialonCatalogController::class, 'storeProject'])->name('store');
            Route::put('/{project}/wialon-mapping', [WialonCatalogController::class, 'updateProjectMapping'])->name('wialon-mapping.update');
            Route::post('/{project}/validate-wialon-mapping', [WialonCatalogController::class, 'validateProjectMapping'])->name('wialon-mapping.validate');
        });

    Route::prefix('admin/historical-recalculations')
        ->name('admin.historical-recalculations.')
        ->middleware('admin')
        ->group(function (): void {
            Route::get('/', [HistoricalRecalculationController::class, 'index'])->name('index');
            Route::post('/preview', [HistoricalRecalculationController::class, 'preview'])->name('preview');
            Route::post('/', [HistoricalRecalculationController::class, 'store'])->name('store');
            Route::post('/pipeline/clear-closed', [HistoricalRecalculationController::class, 'clearClosedPipelines'])->name('pipeline.clear-closed');
            Route::get('/{historicalRecalculation:uuid}', [HistoricalRecalculationController::class, 'show'])->name('show');
            Route::get('/{historicalRecalculation:uuid}/status', [HistoricalRecalculationController::class, 'status'])->name('status');
            Route::post('/{historicalRecalculation:uuid}/cancel', [HistoricalRecalculationController::class, 'cancel'])->name('cancel');
            Route::post('/{historicalRecalculation:uuid}/retry', [HistoricalRecalculationController::class, 'retry'])->name('retry');
            Route::post('/{historicalRecalculation:uuid}/cleanup-stuck', CleanupHistoricalRecalculationQueueController::class)->name('cleanup-stuck');
        });

    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('admin')->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('admin')->name('settings.update');
    Route::post('/settings/sync-units', [SettingsController::class, 'syncUnits'])->name('settings.sync-units');
    Route::post('/settings/sync-geofences', [SettingsController::class, 'syncGeofences'])->middleware('admin')->name('settings.sync-geofences');
    Route::post('/settings/historical-recalculations/cleanup-stuck', [SettingsController::class, 'cleanupHistoricalRuns'])
        ->middleware('admin')
        ->name('settings.cleanup-historical-runs');
});
