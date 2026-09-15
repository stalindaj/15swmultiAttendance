<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureDatabaseIsCurrent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LaptopOnly;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('throttle:20,1')->group(base_path('routes/install.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // tools/gateway.mjs (and the tunnel behind it) run on this machine and pass X-Forwarded-* headers.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->web(append: [
            EnsureAccountActive::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'laptop' => LaptopOnly::class,
            'admin' => RequireAdmin::class,
            'db.current' => EnsureDatabaseIsCurrent::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() && ! $request->header('X-Inertia'),
        );
    })->create();
