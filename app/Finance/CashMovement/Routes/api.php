<?php

use App\Finance\CashMovement\Controllers\CashflowController;

Route::controller(CashflowController::class)->group(function () {
    Route::get('/cash-flow/daily', 'getDaily');
    Route::post('/cash-flow', 'store');
});
