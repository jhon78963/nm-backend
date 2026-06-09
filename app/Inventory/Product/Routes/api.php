<?php

use App\Inventory\Product\Controllers\InventoryReconciliationController;
use App\Inventory\Product\Controllers\ProductController;
use App\Inventory\Product\Controllers\ProductHistoryController;
use App\Inventory\Product\Controllers\ProductMediaController;
use App\Inventory\Product\Controllers\ProductSizeColorController;
use App\Inventory\Product\Controllers\ProductSizeController;
use Illuminate\Support\Facades\Route;

Route::controller(InventoryReconciliationController::class)->group(function (): void {
    Route::get('/inventory/reconciliation/search', 'search')
        ->middleware('permission:inventoryReconciliation.search');
    Route::put('/inventory/reconciliation/{product}', 'update')
        ->middleware('permission:inventoryReconciliation.update');
    Route::post(
        '/inventory/reconciliation/{product}/product-size/{productSize}/replace-color',
        'replaceColor',
    )->middleware('permission:inventoryReconciliation.replaceColor');
});

Route::controller(ProductController::class)->group(function (): void {
    Route::post('/products', 'create')->middleware('permission:product.create');
    Route::patch('/products/{product}', 'update')->middleware('permission:product.update');
    Route::delete('/products/{product}', 'delete')->middleware('permission:product.delete');
    Route::get('/products', 'getAll')->middleware('permission:product.getAll');
    Route::get('/products/{product}', 'get')->middleware('permission:product.get');
});

Route::controller(ProductMediaController::class)->group(function (): void {
    Route::get('/products/{product}/media/{media}/preview', 'preview')
        ->middleware('permission:product.get');
    Route::post('/products/{product}/media', 'store')->middleware('permission:product.update');
    Route::delete('/products/{product}/media/{media}', 'destroy')->middleware('permission:product.update');
});

Route::controller(ProductSizeController::class)->group(function (): void {
    Route::post('/products/{product}/size/{sizeId}', 'add')->middleware('permission:productSize.add');
    Route::patch('/products/{product}/size/{sizeId}', 'modify')->middleware('permission:productSize.modify');
    Route::delete('/products/{product}/size/{sizeId}', 'remove')->middleware('permission:productSize.remove');
    Route::get('/products/{productId}/size/{sizeId}', 'get')->middleware('permission:productSize.get');
});

Route::controller(ProductSizeColorController::class)->group(function (): void {
    Route::post('/product-size/{productSize}/color/{colorId}', 'add')->middleware('permission:productSizeColor.add');
    Route::patch('/product-size/{productSize}/color/{colorId}', 'modify')->middleware('permission:productSizeColor.modify');
    Route::delete('/product-size/{productSize}/color/{colorId}', 'remove')->middleware('permission:productSizeColor.remove');
});

Route::get('/products/{id}/history', [ProductHistoryController::class, 'index'])
    ->middleware('permission:productHistory.index');
