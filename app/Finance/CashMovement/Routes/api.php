<?php

use App\Finance\CashMovement\Controllers\CashflowController;
use Illuminate\Support\Facades\Route;

Route::controller(CashflowController::class)->group(function (): void {
    Route::get('/cash-flow/daily', 'getDaily')->middleware('permission:cashflow.getDaily');
    Route::get('/cash-flow/admin/monthly', 'getAdminMonthlyReport')->middleware('permission:cashflow.getAdminMonthlyReport');
    Route::post('/cash-flow', 'store')->middleware('permission:cashflow.store');
    Route::put('/cash-flow/{id}', 'update')->middleware('permission:cashflow.update');
});
