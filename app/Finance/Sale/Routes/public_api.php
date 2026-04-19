<?php

use App\Finance\Sale\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::get('/print/ticket/{saleId}', [PosController::class, 'printTicket']);
Route::get('/pos/sales/{saleId}/ticket', [PosController::class, 'printTicket']);
Route::get('/pos/sales/{saleId}/ticket/html', [PosController::class, 'printTicket']);
