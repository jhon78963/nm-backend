<?php

use App\Auth\Controllers\AuthController;
use App\Role\Controllers\RoleController;
use App\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/refresh-token', [AuthController::class, 'refreshToken']);
Route::post('auth/logout', [AuthController::class,'logout']);

Route::group([
    'middleware' => 'auth:sanctum',
], function (): void {
    Route::patch('auth/me', [AuthController::class, 'updateMe']);
    Route::post('auth/me', [AuthController::class, 'getMe']);
    Route::post('auth/change-password', [AuthController::class,'changePassword']);

    Route::controller(RoleController::class)->group(function() {
        Route::post('/roles', 'create');
        Route::patch('/roles/{role}', 'update');
        Route::delete('/roles/{role}', 'delete');
        Route::get('/roles', 'getAll');
        Route::get('/roles/{role}', 'get');
    });

    Route::controller(UserController::class)->group(function() {
        Route::post('/users', 'create');
        Route::patch('/users/{user}', 'update');
        Route::delete('/users/{user}', 'delete');
        Route::get('/users', 'getAll');
        Route::get('/users/{user}', 'get');
    });
});
