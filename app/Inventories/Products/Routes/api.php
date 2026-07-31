<?php

use App\Inventories\Products\Controllers\ProductController;
use App\Inventories\Products\Controllers\ProductHistoryController;
use App\Inventories\Products\Controllers\ProductSizeColorController;
use App\Inventories\Products\Controllers\ProductSizeController;
use Illuminate\Support\Facades\Route;

Route::controller(ProductController::class)->group(function (): void {
    Route::post('/products', 'create')->middleware('permission:product.create');
    Route::patch('/products/{product}', 'update')->middleware('permission:product.update');
    Route::delete('/products/{product}', 'delete')->middleware('permission:product.delete');
    Route::get('/products', 'getAll')->middleware('permission:product.getAll');
    Route::get('/products/export/excel', 'export')->middleware('permission:product.getAll');
    Route::post('/products/import/excel', 'import')->middleware('permission:product.update');
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

Route::get('/products/{id}/history', [ProductHistoryController::class, 'index'])
    ->middleware('permission:productHistory.index');
