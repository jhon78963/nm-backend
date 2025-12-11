<?php

use App\Inventory\Warehouse\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::controller(WarehouseController::class)->group(function() {
    Route::post('/warehouses', 'create');
    Route::patch('/warehouses/{warehouse}', 'update');
    Route::delete('/warehouses/{warehouse}', 'delete');
    Route::get('/warehouses', 'getAll');
    Route::get('/warehouses/{warehouse}', 'get');
});
