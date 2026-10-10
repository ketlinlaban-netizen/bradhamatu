<?php

use App\Http\Middleware\EnsureAdminUser;
use App\Http\Middleware\EnsureCustomerUser;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustCloudflareProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->append(TrustCloudflareProxies::class);
        $middleware->alias([
            'admin.user' => EnsureAdminUser::class,
            'customer.user' => EnsureCustomerUser::class,
        ]);
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login',
        );
    })
    ->withSchedule(function () {
        Schedule::command('billing:process-expired')->everyFiveMinutes();
        Schedule::command('billing:retry-activations')->everyTenMinutes();
        Schedule::command('billing:reconcile-payments')->everyFiveMinutes();
        Schedule::command('billing:process-activations')->everyTwoMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
