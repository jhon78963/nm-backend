<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            // Spatie Permission
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Feature Toggling: bloquea rutas si el tenant no tiene el módulo activo.
            // Uso: ->middleware('check.feature:electronic_billing')
            'check.feature'      => \App\Shared\Foundation\Middleware\CheckTenantFeature::class,
            // System Admin: restringe el acceso al tenant 1 (SaaS Provider).
            'system.admin'       => \App\Shared\Foundation\Middleware\CheckSystemAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withCommands([
        \App\Inventory\Product\Jobs\SyncZeroToMultikartJob::class,
    ])
    ->create();
