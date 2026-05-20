<?php

use App\Finance\FinancialSummary\Controllers\FinancialSummaryController;
use Illuminate\Support\Facades\Route;

Route::get('/financial/summary', [FinancialSummaryController::class, 'getSummary'])
    ->middleware('permission:financialSummary.getSummary');
