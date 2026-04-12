<?php

use App\Finance\CashMovement\Controllers\CashflowController;
use Illuminate\Support\Facades\Route;

Route::controller(CashflowController::class)->group(function () {
    Route::get('/cash-flow/daily', 'getDaily');
    Route::get('/cash-flow/admin/monthly', 'getAdminMonthlyReport');
    Route::post('/cash-flow', 'store');
    Route::patch('/cash-flow/{id}', 'update');
});
