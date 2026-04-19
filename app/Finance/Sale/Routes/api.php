<?php

use App\Finance\Sale\Controllers\PosController;
use App\Finance\Sale\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::controller(PosController::class)->group(function () {
    Route::get('/pos/products', 'searchProduct');
    Route::get('/pos/customers', 'searchCustomer');
    Route::post('/pos/checkout', 'checkout');
});

Route::controller(SaleController::class)->group(function () {
    Route::patch('/sales/{sale}', 'update');
    Route::delete('/sales/{sale}', 'delete');
    Route::get('/sales', 'getAll');
    Route::get('/sales/monthly-stats', 'getMonthlyStats');
    Route::get('/sales/{sale}', 'get');
    Route::post('/sales/exchange', 'exchange');
});
