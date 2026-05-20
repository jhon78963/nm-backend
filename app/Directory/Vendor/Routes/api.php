<?php

use App\Directory\Vendor\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

Route::controller(VendorController::class)->group(function (): void {
    Route::post('/vendors', 'create')->middleware('permission:vendor.create');
    Route::patch('/vendors/{vendor}', 'update')->middleware('permission:vendor.update');
    Route::delete('/vendors/{vendor}', 'delete')->middleware('permission:vendor.delete');
    Route::get('/vendors', 'getAll')->middleware('permission:vendor.getAll');
    Route::get('/vendors/{vendor}', 'get')->middleware('permission:vendor.get');
});
