<?php

use App\Finance\Order\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::controller(OrderController::class)->group(function () {
    Route::post('/orders', 'create');
    Route::patch('/orders/{order}', 'update');
    Route::delete('/orders/{order}', 'delete');
    Route::get('/orders', 'getAll');
    Route::get('/orders/{order}', 'get');
});
