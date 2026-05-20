<?php

use App\Inventory\Color\Controllers\ColorController;
use Illuminate\Support\Facades\Route;

Route::controller(ColorController::class)->group(function (): void {
    Route::post('/colors', 'create')->middleware('permission:color.create');
    Route::patch('/colors/{color}', 'update')->middleware('permission:color.update');
    Route::delete('/colors/{color}', 'delete')->middleware('permission:color.delete');
    Route::get('/colors', 'getAll')->middleware('permission:color.getAll');
    Route::get('/colors/selected-attached', 'getAllSelectedAttached')->middleware('permission:color.getAllSelectedAttached');
    Route::get('/colors/selected', 'getAllSelected')->middleware('permission:color.getAllSelected');
    Route::get('/colors/sizes', 'getSizes')->middleware('permission:color.getSizes');
    Route::get('/colors/autocomplete', 'getAllAutocomplete')->middleware('permission:color.getAllAutocomplete');
    Route::get('/colors/{color}', 'get')->middleware('permission:color.get');
});
