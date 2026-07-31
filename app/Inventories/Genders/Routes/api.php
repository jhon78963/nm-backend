<?php

use App\Inventories\Genders\Controllers\GenderController;
use Illuminate\Support\Facades\Route;

Route::controller(GenderController::class)->group(function (): void {
    Route::get('/genders', 'getAll')->middleware('permission:gender.getAll');
    Route::get('/genders/{gender}', 'get')->middleware('permission:gender.get');
});
