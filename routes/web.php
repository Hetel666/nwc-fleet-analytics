<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardExportController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentTypeController;
use App\Http\Controllers\GeofenceController;
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
    Route::get('/dashboard/export', DashboardExportController::class)->name('dashboard.export');
    Route::get('/projects/{project}/dashboard', [ProjectDashboardController::class, 'show'])->name('projects.dashboard');

    Route::resource('projects', ProjectController::class)->except(['show'])->middleware('admin');
    Route::resource('equipment-types', EquipmentTypeController::class)->except(['show'])->middleware('admin');
    Route::resource('equipment', EquipmentController::class)->except(['show'])->middleware('admin');
    Route::resource('geofences', GeofenceController::class)->except(['show'])->middleware('admin');
    Route::resource('users', UserController::class)->except(['show'])->middleware('admin');

    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('admin')->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('admin')->name('settings.update');
    Route::post('/settings/sync-units', [SettingsController::class, 'syncUnits'])->middleware('admin')->name('settings.sync-units');
    Route::post('/settings/sync-geofences', [SettingsController::class, 'syncGeofences'])->middleware('admin')->name('settings.sync-geofences');
});
