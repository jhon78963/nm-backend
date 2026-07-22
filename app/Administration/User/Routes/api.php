<?php

use App\Administration\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::controller(UserController::class)->group(function (): void {
    Route::post('/users', 'create')->middleware('permission:user.create');
    Route::patch('/users/{user}', 'update')->middleware('permission:user.update');
    Route::patch('/users/{user}/password', 'resetPassword')->middleware('permission:user.update');
    Route::delete('/users/{user}', 'delete')->middleware('permission:user.delete');
    Route::get('/users', 'getAll')->middleware('permission:user.getAll');
    Route::get('/users/{user}', 'get')->middleware('permission:user.get');
});
