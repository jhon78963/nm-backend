<?php

use App\Finance\Sale\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pos/sales/{saleId}/ticket', [PosController::class, 'printTicket']);
