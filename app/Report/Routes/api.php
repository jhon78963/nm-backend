<?php

use App\Report\Controllers\ReportController;

Route::get('/reports/dashboard', [ReportController::class, 'index']);
Route::get('/reports/products', [ReportController::class, 'products']);
Route::get('/reports/products/export/pdf', [ReportController::class, 'productsPdf']);
