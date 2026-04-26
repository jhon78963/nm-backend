<?php

use App\Directory\Team\Controllers\AttendanceController;
use App\Directory\Team\Controllers\PaymentController;
use App\Directory\Team\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::controller(TeamController::class)->group(function () {
    Route::post('/teams', 'create');
    Route::patch('/teams/{team}', 'update');
    Route::delete('/teams/{team}', 'delete');
    Route::get('/teams', 'getAll');
    Route::get('/teams/{team}', 'get');
});

Route::controller(AttendanceController::class)->group(function () {
    Route::get('/attendance/daily-summary', 'getDailySummary');
    Route::get('/attendance/{teamId}', 'getByMonth');
    Route::post('/attendance', 'store');
});

Route::controller(PaymentController::class)->group(function () {
    Route::get('/payments/payroll', 'getPayroll');
    Route::get('/payments', 'getByMonth');
    Route::post('/payments', 'store');
});
