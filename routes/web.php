<?php

use App\Finance\Sale\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Tickets POS — URLs firmadas temporalmente (sin auth:sanctum en el navegador)
|--------------------------------------------------------------------------
| El iframe de impresión no envía Bearer token; la firma HMAC sustituye la sesión.
| Obtener la URL firmada vía API autenticada: GET /api/pos/sales/{saleId}/ticket-url
*/
Route::middleware('signed')->group(function (): void {
    Route::get('/pos/sales/{saleId}/ticket', [PosController::class, 'printTicket'])
        ->name('pos.sales.ticket');

    Route::get('/pos/sales/{saleId}/ticket/html', [PosController::class, 'printTicket'])
        ->name('pos.sales.ticket.html');

    Route::get('/print/ticket/{saleId}', [PosController::class, 'printTicket'])
        ->name('print.ticket');
});
