<?php

use App\Size\Controllers\SizeController;
use Illuminate\Support\Facades\Route;

Route::controller(SizeController::class)->group(function() {
    Route::post('/sizes', 'create');
    Route::patch('/sizes/{size}', 'update');
    Route::delete('/sizes/{size}', 'delete');
    Route::get('/sizes', 'getAll');
    Route::get('/sizes/autocomplete', 'getAllAutocomplete');
    Route::get('/sizes/autocomplete/{size}', 'getAutocomplete');
    Route::get('/sizes/{size}', 'get');
});
