<?php

use App\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// En local/testing subimos el límite para no bloquear el desarrollo.
// Cada ruta tiene su propio prefijo de throttle; si comparten prefijo vacío,
// Laravel agrupa TODAS en un solo contador por IP (5 intentos totales, no por ruta).
$isDev = app()->environment('local', 'testing');
$loginLimit = $isDev ? '20,1' : '5,1';
$recoveryLimit = $isDev ? '10,1' : '5,1';

Route::get('auth/csrf-token', [AuthController::class, 'csrfToken'])
    ->middleware('throttle:60,1,auth-csrf');

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware("throttle:{$loginLimit},auth-login");

Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware("throttle:{$recoveryLimit},auth-forgot");

Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware("throttle:{$recoveryLimit},auth-reset");

Route::post('auth/refresh', [AuthController::class, 'refresh'])
    ->middleware('throttle:30,1,auth-refresh');
