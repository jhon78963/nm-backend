<?php

use App\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});
