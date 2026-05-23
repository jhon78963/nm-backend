<?php

use App\Auth\Enums\TokenAbility;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| 1. Rutas públicas (public_api.php)
|--------------------------------------------------------------------------
| Catálogo ecommerce, login, etc. Sin auth:sanctum.
| Auth público (csrf-token, login, refresh): throttle:5,1 en
| app/Auth/Routes/public_api.php.
*/
$publicFiles = Finder::create()
    ->in(app_path())
    ->name('public_api.php')
    ->path('Routes');

foreach ($publicFiles as $file) {
    require $file->getRealPath();
}

/*
|--------------------------------------------------------------------------
| 2. Rutas autenticadas (api.php)
|--------------------------------------------------------------------------
| Capa base: auth:sanctum + ability access-api (rechaza refresh tokens como Bearer).
| Cada módulo en app/{Modulo}/Routes/api.php declara middleware('permission:...')
| (Spatie Permission). Super Admin omite permisos vía Gate::before, pero el
| aislamiento por warehouse_id (WarehouseScope) siempre aplica.
|
| Vendedora / Vendedor: solo POS (pos.*) y caja diaria (cashflow.getDaily,
| cashflow.store). Sin inventario, productos, ventas listado, compras ni admin.
|
| Gastos administrativos y de tienda: unificados en /cash-flow (cash_movements).
| La API legacy /expenses fue retirada.
 */
Route::middleware([
    'auth:sanctum',
    'ability:'.TokenAbility::ACCESS_API->value,
    'force.password.change',
    'throttle:120,1',
])->group(function (): void {
    $protectedFiles = Finder::create()
        ->in(app_path())
        ->name('api.php')
        ->path('Routes')
        ->sortByName();

    foreach ($protectedFiles as $file) {
        require $file->getRealPath();
    }
});
