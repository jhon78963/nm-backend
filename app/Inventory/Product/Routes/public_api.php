<?php

/*
|--------------------------------------------------------------------------
| Catálogo público ecommerce (Multikart) — DESACTIVADO
|--------------------------------------------------------------------------
|
| Desactivado por seguridad. El catálogo público será movido a un
| microservicio independiente (ej. nn-ecommerce) cuando sea requerido.
|
| Controlador conservado (no eliminar):
| App\Inventory\Product\Controllers\ProductEcommerceController
|
| use App\Inventory\Product\Controllers\ProductEcommerceController;
| use Illuminate\Support\Facades\Route;
|
| Route::middleware('throttle:30,1')->controller(ProductEcommerceController::class)->group(function (): void {
|     Route::get('/prod/ecommerce', 'index');
|     Route::get('/prod/ecommerce/{id}', 'show');
| });
*/
