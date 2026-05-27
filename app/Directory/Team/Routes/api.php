<?php

use App\Directory\Team\Controllers\AttendanceController;
use App\Directory\Team\Controllers\PaymentController;
use App\Directory\Team\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::controller(TeamController::class)->group(function (): void {
    Route::post('/teams', 'create')->middleware('permission:team.create');
    Route::patch('/teams/{team}', 'update')->middleware('permission:team.update');
    Route::delete('/teams/{team}', 'delete')->middleware('permission:team.delete');
    Route::get('/teams', 'getAll')->middleware('permission:team.getAll');
    Route::get('/teams/{team}', 'get')->middleware('permission:team.get');
});

Route::controller(AttendanceController::class)->group(function (): void {
    Route::get('/attendance/daily-summary', 'getDailySummary')->middleware('permission:team.getAttendanceDailySummary');
    Route::get('/attendance/{teamId}', 'getByMonth')->middleware('permission:team.getAttendanceByMonth');
    Route::post('/attendance', 'store')->middleware('permission:team.storeAttendance');
});

Route::controller(PaymentController::class)->group(function (): void {
    Route::get('/payments/payroll', 'getPayroll')->middleware('permission:team.getPaymentByMonth');
    Route::get('/payments', 'getByMonth')->middleware('permission:team.getPaymentByMonth');
    Route::post('/payments', 'store')->middleware('permission:team.storePayment');
    Route::patch('/payments/{teamPayment}', 'update')->middleware('permission:team.storePayment');
    Route::delete('/payments/{teamPayment}', 'destroy')->middleware('permission:team.storePayment');
});
