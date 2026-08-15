<?php

use App\Administration\Audit\Middleware\LogUserActivity;
use App\Auth\Middleware\EnsureUserIsEnabled;
use App\Auth\Middleware\ForcePasswordChange;
use App\Console\Commands\ConfigCheckSecurityCommand;
use App\Console\Commands\InventoryCheckMismatchesCommand;
use App\Console\Commands\InventoryFixMismatchesCommand;
use App\Inventory\InventoryLedger\Command\InventoryAuditMissingBalancesCommand;
use App\Inventory\InventoryLedger\Command\InventoryBackfillMissingBalancesCommand;
use App\Inventory\InventoryLedger\Command\SeedInitialInventoryMovementsCommand;
use App\Inventory\InventoryLedger\Console\MigrateLegacyStockCommand;
use App\Shared\Foundation\Exceptions\ApiExceptionRenderer;
use App\Shared\Foundation\Middleware\SecurityHeaders;
use App\Shared\Foundation\Support\LegacyModelAliases;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->booting(function (): void {
        JsonResource::withoutWrapping();
        LegacyModelAliases::register();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // API JSON/SPA: sin ruta web `login`; evita 500 (Route [login] not defined) en 401.
        $middleware->redirectGuestsTo(fn () => null);

        // SPA Sanctum (cookies): sesión + CSRF en peticiones stateful desde dominios en config/sanctum.php.
        // Debe ir primero en el grupo `api` (EnsureFrontendRequestsAreStateful).
        $middleware->statefulApi();

        // SEC-004: headers HTTP en todas las rutas api/* (nginx puede duplicar/reforzar).
        $middleware->appendToGroup('api', SecurityHeaders::class);
        $middleware->appendToGroup('api', EnsureUserIsEnabled::class);
        $middleware->appendToGroup('api', LogUserActivity::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'force.password.change' => ForcePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiExceptionRenderer::render($exception, $request);
        });
    })
    ->withCommands([
        ConfigCheckSecurityCommand::class,
        InventoryCheckMismatchesCommand::class,
        InventoryFixMismatchesCommand::class,
        MigrateLegacyStockCommand::class,
        InventoryAuditMissingBalancesCommand::class,
        InventoryBackfillMissingBalancesCommand::class,
        SeedInitialInventoryMovementsCommand::class,
    ])
    ->create();
