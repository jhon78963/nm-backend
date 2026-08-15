<?php

use App\Dashboard\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);
