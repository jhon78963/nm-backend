<?php

use App\Inventory\Warehouse\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::controller(WarehouseController::class)->group(function (): void {
    Route::post('/warehouses', 'create')->middleware('permission:warehouse.create');
    Route::patch('/warehouses/{warehouse}', 'update')->middleware('permission:warehouse.update');
    Route::delete('/warehouses/{warehouse}', 'delete')->middleware('permission:warehouse.delete');
    Route::get('/warehouses', 'getAll')->middleware('permission:warehouse.getAll');
    Route::get('/warehouses/{warehouse}', 'get')->middleware('permission:warehouse.get');
});
