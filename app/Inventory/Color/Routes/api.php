<?php

use App\Inventory\Color\Controllers\ColorController;
use Illuminate\Support\Facades\Route;

Route::controller(ColorController::class)->group(function() {
    Route::post('/colors', 'create');
    Route::patch('/colors/{color}', 'update');
    Route::delete('/colors/{color}', 'delete');
    Route::get('/colors', 'getAll');
    Route::get('/colors/selected', 'getAllSelected');
    Route::get('/colors/sizes', 'getSizes');
    Route::get('/colors/autocomplete', 'getAllAutocomplete');
    Route::get('/colors/{color}', 'get');
});
