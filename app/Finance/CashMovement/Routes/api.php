<?php

use App\Finance\CashMovement\Controllers\CashflowController;
use Illuminate\Support\Facades\Route;

Route::controller(CashflowController::class)->group(function (): void {
    Route::get('/cash-flow/vouchers/preview', 'streamVoucher')
        ->middleware('permission:cashflow.getDaily|cashflow.getAdminMonthlyReport|cashflow.getAccumulatedExpensesReport|team.getPaymentByMonth|purchase.get');
    Route::get('/cash-flow/daily', 'getDaily')->middleware('permission:cashflow.getDaily');
    Route::get('/cash-flow/admin/monthly', 'getAdminMonthlyReport')->middleware('permission:cashflow.getAdminMonthlyReport');
    Route::get('/cash-flow/accumulated/monthly', 'getAccumulatedMonthlyReport')->middleware('permission:cashflow.getAccumulatedExpensesReport');
    Route::post('/cash-flow', 'store')->middleware('permission:cashflow.store');
    Route::put('/cash-flow/{cashMovement}', 'update')->middleware('permission:cashflow.update');
    Route::delete('/cash-flow/{cashMovement}', 'destroy')->middleware('permission:cashflow.update');
});
