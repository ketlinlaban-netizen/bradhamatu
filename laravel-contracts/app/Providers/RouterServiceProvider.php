<?php

namespace App\Providers;

use App\Services\RouterProvider;
use App\Services\RouterService;
use App\Services\MikroTikRouterProvider;
use App\Services\RouterReadOnlyGuard;
use Illuminate\Support\ServiceProvider;

/**
 * RouterServiceProvider
 *
 * Binds the RouterProvider interface to the configured implementation.
 * Binds the router interface to the live MikroTik API provider.
 */
class RouterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RouterProvider::class, MikroTikRouterProvider::class);

        // Bind RouterService with the guard
        $this->app->bind(RouterService::class, function ($app) {
            return new RouterService(
                $app->make(RouterProvider::class),
                new RouterReadOnlyGuard()
            );
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            base_path('config/mikrotik.php') => config_path('mikrotik.php'),
        ], 'mikrotik-config');
    }
}
