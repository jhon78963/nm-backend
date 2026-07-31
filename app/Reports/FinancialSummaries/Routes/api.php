<?php

use App\Reports\FinancialSummaries\Controllers\FinancialSummaryController;
use Illuminate\Support\Facades\Route;

Route::get('/financial/summary', [FinancialSummaryController::class, 'getSummary'])
    ->middleware('permission:financialSummary.getSummary');
