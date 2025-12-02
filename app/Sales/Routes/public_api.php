<?php

use App\Sales\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::get('/print/ticket/{id}', [SaleController::class, 'ticket']);
