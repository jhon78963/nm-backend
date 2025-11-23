<?php

use App\Directory\Customer\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::controller(CustomerController::class)->group(function () {
    Route::post('/customers', 'create');
    Route::patch('/customers/{customer}', 'update');
    Route::delete('/customers/{customer}', 'delete');
    Route::get('/customers', 'getAll');
    Route::get('/customers/{customer}', 'get');
});
