<?php

namespace App\Providers;

use App\Services\EvilFreelancerRouterOsApiClientFactory;
use App\Services\MikroTikRouterProvider;
use App\Services\MockRouterProvider;
use App\Services\RouterOsApiClientFactory;
use App\Services\RouterOsApiTransport;
use App\Services\RouterProvider;
use App\Services\RouterReadOnlyGuard;
use App\Services\RouterReadTransport;
use App\Services\RouterRestProvider;
use App\Services\RouterRestTransport;
use App\Services\RouterService;
use Illuminate\Support\ServiceProvider;

/**
 * RouterServiceProvider
 *
 * Binds the configured RouterProvider and its selected read-only transport.
 */
class RouterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            RouterOsApiClientFactory::class,
            EvilFreelancerRouterOsApiClientFactory::class,
        );

        $this->app->bind(RouterReadTransport::class, function ($app): RouterReadTransport {
            return match (config('mikrotik.provider')) {
                'mock', 'mikrotik' => $app->make(RouterOsApiTransport::class),
                'rest' => $app->make(RouterRestTransport::class),
                default => throw new \InvalidArgumentException(
                    'ROUTER_PROVIDER must be "mock", "mikrotik", or "rest".',
                ),
            };
        });

        $this->app->bind(RouterProvider::class, function ($app): RouterProvider {
            return match (config('mikrotik.provider')) {
                'mock' => $app->make(MockRouterProvider::class),
                'mikrotik' => $app->make(MikroTikRouterProvider::class),
                'rest' => $app->make(RouterRestProvider::class),
                default => throw new \InvalidArgumentException(
                    'ROUTER_PROVIDER must be "mock", "mikrotik", or "rest".',
                ),
            };
        });

        $this->app->bind(RouterService::class, function ($app) {
            return new RouterService(
                $app->make(RouterProvider::class),
                new RouterReadOnlyGuard
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            base_path('config/mikrotik.php') => config_path('mikrotik.php'),
        ], 'mikrotik-config');
    }
}
