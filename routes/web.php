<?php

use App\Sales\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pos/sales/{saleId}/ticket', [SaleController::class, 'printTicket']);
