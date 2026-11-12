<?php

use App\Http\Middleware\ApplyMailSettings;
use App\Http\Middleware\EnsureModuleActive;
use App\Http\Middleware\EnsureSetupCompleted;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use OwenIt\Auditing\AuditingServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AuditingServiceProvider::class,
        PermissionServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        $middleware->web(append: [
            EnsureSetupCompleted::class,
            EnsureUserIsActive::class,
            ApplyMailSettings::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'module' => EnsureModuleActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
