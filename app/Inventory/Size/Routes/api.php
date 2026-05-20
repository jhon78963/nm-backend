<?php

use App\Inventory\Size\Controllers\SizeController;
use Illuminate\Support\Facades\Route;

Route::controller(SizeController::class)->group(function (): void {
    Route::post('/sizes', 'create')->middleware('permission:size.create');
    Route::patch('/sizes/{size}', 'update')->middleware('permission:size.update');
    Route::delete('/sizes/{size}', 'delete')->middleware('permission:size.delete');
    Route::get('/sizes', 'getAll')->middleware('permission:size.getAll');
    Route::get('/sizes/selected', 'getAllSelected')->middleware('permission:size.getAllSelected');
    Route::get('/sizes/autocomplete', 'getAllAutocomplete')->middleware('permission:size.getAllAutocomplete');
    Route::get('/sizes/autocomplete/{size}', 'getAutocomplete')->middleware('permission:size.getAutocomplete');
    Route::get('/sizes/{size}', 'get')->middleware('permission:size.get');
    Route::get('/size-types', 'getSizeType')->middleware('permission:size.getSizeType');
});
