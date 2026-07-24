<?php

namespace App\Providers;

use App\Contracts\UnitPositionSource;
use App\Services\LocalStoredUnitPositionSource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UnitPositionSource::class, LocalStoredUnitPositionSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}
