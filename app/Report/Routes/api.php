<?php

use App\Report\Controllers\ReportController;

Route::get('/reports/dashboard', [ReportController::class, 'index']);
