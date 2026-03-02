<?php

use App\Inventory\Product\Controllers\ProductEcommerceController;
use Illuminate\Support\Facades\Route;

Route::controller(ProductEcommerceController::class)->group(function(): void {
    Route::get('/prod/ecommerce', 'index');
    Route::get('/prod/ecommerce/{id}', 'show');
});
