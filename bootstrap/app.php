<?php

use App\Shared\Foundation\Exceptions\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->booting(function (): void {
        JsonResource::withoutWrapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // API JSON/SPA: sin ruta web `login`; evita 500 (Route [login] not defined) en 401.
        $middleware->redirectGuestsTo(fn () => null);

        // SPA Sanctum (cookies): sesión + CSRF en peticiones stateful desde dominios en config/sanctum.php.
        // Debe ir primero en el grupo `api` (EnsureFrontendRequestsAreStateful).
        $middleware->statefulApi();

        // SEC-004: headers HTTP en todas las rutas api/* (nginx puede duplicar/reforzar).
        $middleware->appendToGroup('api', \App\Shared\Foundation\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'force.password.change' => \App\Auth\Middleware\ForcePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiExceptionRenderer::render($exception, $request);
        });
    })
    ->withCommands([
        \App\Console\Commands\ConfigCheckSecurityCommand::class,
        \App\Console\Commands\InventoryCheckMismatchesCommand::class,
        \App\Console\Commands\InventoryFixMismatchesCommand::class,
        \App\Inventory\InventoryLedger\Console\MigrateLegacyStockCommand::class,
        \App\Inventory\InventoryLedger\Command\InventoryAuditMissingBalancesCommand::class,
        \App\Inventory\InventoryLedger\Command\InventoryBackfillMissingBalancesCommand::class,
        \App\Inventory\InventoryLedger\Command\SeedInitialInventoryMovementsCommand::class,
    ])
    ->create();
