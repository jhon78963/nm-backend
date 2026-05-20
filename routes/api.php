<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| 1. Rutas públicas (public_api.php)
|--------------------------------------------------------------------------
| Catálogo ecommerce, login, tickets POS, etc. Sin auth:sanctum.
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
| Capa base: auth:sanctum. Cada módulo en app/{Modulo}/Routes/api.php declara
| middleware('permission:modulo.accion') por grupo o ruta (Spatie Permission).
| Super Admin omite comprobaciones vía Gate::before en AuthorizationServiceProvider.
|
| Vendedora: POS, consultas de venta/producto/stock y clientes. Sin admin,
| reportes, anulaciones, compras, cuadre de inventario ni kardex.
*/
Route::middleware(['auth:sanctum'])->group(function (): void {
    $protectedFiles = Finder::create()
        ->in(app_path())
        ->name('api.php')
        ->path('Routes')
        ->sortByName();

    foreach ($protectedFiles as $file) {
        require $file->getRealPath();
    }
});
