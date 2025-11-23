<?php

use App\Directory\Vendor\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

Route::controller(VendorController::class)->group(function () {
    Route::post('/vendors', 'create');
    Route::patch('/vendors/{vendor}', 'update');
    Route::delete('/vendors/{vendor}', 'delete');
    Route::get('/vendors', 'getAll');
    Route::get('/vendors/{vendor}', 'get');
});
