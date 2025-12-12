<?php

use App\Sale\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::controller(SaleController::class)->group(function () {
    Route::get('/sales', 'getAll');
    Route::get('/pos/products', 'searchProduct');
    Route::get('/pos/customers', 'searchCustomer');
    Route::post('/pos/checkout', 'checkout');
});
