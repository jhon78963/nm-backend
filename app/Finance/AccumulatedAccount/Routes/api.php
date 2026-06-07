<?php

use App\Finance\AccumulatedAccount\Controllers\AccumulatedAccountController;
use Illuminate\Support\Facades\Route;

Route::controller(AccumulatedAccountController::class)->group(function (): void {
    Route::get('/accumulated-account/settings', 'showSettings')
        ->middleware('permission:cashflow.getAccumulatedExpensesReport');
    Route::post('/accumulated-account/initialize', 'initializeSettings')
        ->middleware('permission:cashflow.getAccumulatedExpensesReport');
    Route::put('/accumulated-account/settings', 'updateSettings')
        ->middleware('permission:cashflow.getAccumulatedExpensesReport');
    Route::get('/accumulated-account/month-end-transfer/preview', 'monthEndTransferPreview')
        ->middleware('permission:cashflow.getAccumulatedExpensesReport');
    Route::get('/accumulated-account/month-end-transfers', 'listMonthEndTransfers')
        ->middleware('permission:cashflow.getAccumulatedExpensesReport');
    Route::post('/accumulated-account/month-end-transfer', 'recordMonthEndTransfer')
        ->middleware('permission:cashflow.getAccumulatedExpensesReport');
});
