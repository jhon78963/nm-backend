<?php

use App\Inventory\Product\Controllers\InventoryReconciliationController;
use App\Inventory\Product\Controllers\ProductController;
use App\Inventory\Product\Controllers\ProductHistoryController;
use App\Inventory\Product\Controllers\ProductImageController;
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

Route::controller(ProductImageController::class)->group(function (): void {
    Route::post('/products/{product}/upload/image', 'add')->middleware('permission:productImage.add');
    Route::post('/products/{product}/upload/images', 'multipleAdd')->middleware('permission:productImage.multipleAdd');
    Route::post('/products/{product}/remove/images', 'multipleRemove')->middleware('permission:productImage.multipleRemove');
    Route::delete('/products/{product}/image/{path}', 'remove')->where('path', '.*')->middleware('permission:productImage.remove');
    Route::get('/products/{product}/images', 'getAll')->middleware('permission:productImage.getAll');
});

Route::get('/products/{id}/history', [ProductHistoryController::class, 'index'])
    ->middleware('permission:productHistory.index');
