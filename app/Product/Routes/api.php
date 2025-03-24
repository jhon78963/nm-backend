<?php

use App\Product\Controllers\ProductColorController;
use App\Product\Controllers\ProductController;
use App\Product\Controllers\ProductSizeController;
use Illuminate\Support\Facades\Route;

Route::controller(ProductController::class)->group(function(): void {
    Route::post('/products', 'create');
    Route::patch('/products/{product}', 'update');
    Route::delete('/products/{product}', 'delete');
    Route::get('/products', 'getAll');
    Route::get('/products/{product}', 'get');
});

Route::controller(ProductColorController::class)->group(function(): void {
    Route::post('/products/{product}/color/{colorId}', 'add');
    Route::patch('/products/{product}/color/{colorId}', 'modify');
    Route::delete('/products/{product}/color/{colorId}', 'remove');
});

Route::controller(ProductSizeController::class)->group(function(): void {
    Route::post('/products/{product}/size/{sizeId}', 'add');
    Route::patch('/products/{product}/size/{sizeId}', 'modify');
    Route::delete('/products/{product}/size/{sizeId}', 'remove');
});
