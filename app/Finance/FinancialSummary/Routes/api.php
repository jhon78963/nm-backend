<?php

use App\Finance\FinancialSummary\Controllers\FinancialSummaryController;

Route::get('/financial/summary', [FinancialSummaryController::class, 'getSummary']);
