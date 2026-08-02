<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Gate::define('manage-dashboard-layout', fn ($user): bool => $user->isAdmin());
        Gate::define('manage-historical-recalculations', fn ($user): bool => $user->isAdmin());
        Gate::define('view-wialon-catalog', fn ($user): bool => $user->hasPermission('wialon_catalog.view'));
        Gate::define('sync-wialon-catalog', fn ($user): bool => $user->hasPermission('wialon_catalog.sync'));
        Gate::define('manage-projects', fn ($user): bool => $user->hasPermission('projects.manage'));
    }
}
