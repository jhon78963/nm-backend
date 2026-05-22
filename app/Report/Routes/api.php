<?php

use App\Report\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports/dashboard', [ReportController::class, 'index'])
    ->middleware('permission:report.index');
Route::get('/reports/products', [ReportController::class, 'products'])
    ->middleware('permission:report.products');
Route::get('/reports/products/export/pdf', [ReportController::class, 'productsPdf'])
    ->middleware('permission:report.products');
