<?php

use App\Inventory\Product\Controllers\ProductController;
use App\Inventory\Product\Controllers\ProductHistoryController;
use App\Inventory\Product\Controllers\ProductImageController;
use App\Inventory\Product\Controllers\ProductSizeColorController;
use App\Inventory\Product\Controllers\ProductSizeController;
use Illuminate\Support\Facades\Route;

Route::controller(ProductController::class)->group(function(): void {
    Route::post('/products', 'create');
    Route::patch('/products/{product}', 'update');
    Route::delete('/products/{product}', 'delete');
    Route::get('/products', 'getAll');
    Route::get('/products/{product}', 'get');
});

Route::controller(ProductSizeController::class)->group(function(): void {
    Route::post('/products/{product}/size/{sizeId}', 'add');
    Route::patch('/products/{product}/size/{sizeId}', 'modify');
    Route::delete('/products/{product}/size/{sizeId}', 'remove');
    Route::get('/products/{productId}/size/{sizeId}', 'get');
});

Route::controller(ProductSizeColorController::class)->group(function(): void {
    Route::post('/product-size/{productSize}/color/{colorId}', 'add');
    Route::patch('/product-size/{productSize}/color/{colorId}', 'modify');
    Route::delete('/product-size/{productSize}/color/{colorId}', 'remove');
});

Route::controller(ProductImageController::class)->group(function(): void {
    Route::post('/products/{product}/upload/image', 'add');
    Route::post('/products/{product}/upload/images', 'multipleAdd');
    Route::post('/products/{product}/remove/images', 'multipleRemove');
    Route::delete('/products/{product}/image/{path}', 'remove')->where('path', '.*');
    Route::get('/products/{product}/images', 'getAll');
});

Route::get('/products/{id}/history', [ProductHistoryController::class, 'index']);
