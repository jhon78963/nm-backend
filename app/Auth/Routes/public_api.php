<?php

use App\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->group(function (): void {
    Route::get('auth/csrf-token', [AuthController::class, 'csrfToken']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
});
