<?php

use App\Sales\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::get('/print/ticket/{id}', [SaleController::class, 'ticket']);
Route::get('/pos/sales/{saleId}/ticket/html', [SaleController::class, 'getTicketHtml']);
Route::get('/pos/sales/{saleId}/ticket/base64', [SaleController::class, 'getTicketBase64']);
