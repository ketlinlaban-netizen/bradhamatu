<?php

namespace App\Providers;

use App\Services\RouterProvider;
use App\Services\RouterService;
use App\Services\MockRouterProvider;
use App\Services\MikroTikRouterProvider;
use App\Services\RouterReadOnlyGuard;
use Illuminate\Support\ServiceProvider;

/**
 * RouterServiceProvider
 *
 * Binds the RouterProvider interface to the configured implementation.
 * Set ROUTER_PROVIDER=mikrotik in .env to use real routers,
 * or ROUTER_PROVIDER=mock (default) for demo data.
 */
class RouterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the provider based on config
        $this->app->bind(RouterProvider::class, function ($app) {
            $provider = config('mikrotik.provider', 'mock');
            return $provider === 'mikrotik'
                ? new MikroTikRouterProvider()
                : new MockRouterProvider();
        });

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
            __DIR__ . '/../config/mikrotik.php' => config_path('mikrotik.php'),
        ], 'mikrotik-config');
    }
}
