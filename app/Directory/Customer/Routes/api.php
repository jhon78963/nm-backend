<?php

use App\Directory\Customer\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::controller(CustomerController::class)->group(function (): void {
    Route::post('/customers', 'create')->middleware('permission:customer.create');
    Route::patch('/customers/{customer}', 'update')->middleware('permission:customer.update');
    Route::delete('/customers/{customer}', 'delete')->middleware('permission:customer.delete');
    Route::get('/customers', 'getAll')->middleware('permission:customer.getAll');
    Route::get('/customers/{customer}', 'get')->middleware('permission:customer.get');
});
