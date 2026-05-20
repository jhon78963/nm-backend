<?php

use App\Finance\Order\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::controller(OrderController::class)->group(function (): void {
    Route::post('/orders', 'create')->middleware('permission:order.create');
    Route::patch('/orders/{order}', 'update')->middleware('permission:order.update');
    Route::delete('/orders/{order}', 'delete')->middleware('permission:order.delete');
    Route::get('/orders', 'getAll')->middleware('permission:order.getAll');
    Route::get('/orders/{order}', 'get')->middleware('permission:order.get');
});
