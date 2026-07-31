<?php

use App\Finances\Pos\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::controller(PosController::class)->group(function (): void {
    Route::get('/pos/products', 'searchProduct')->middleware('permission:pos.searchProduct');
    Route::get('/pos/customers', 'searchCustomer')->middleware('permission:pos.searchCustomer');
    Route::post('/pos/checkout', 'checkout')->middleware('permission:pos.checkout');
    Route::get('/pos/sales/{saleId}/ticket-url', 'ticketUrl')
        ->middleware('permission:sale.get|pos.checkout');
    Route::get('/pos/sales/{saleId}/ticket', 'printTicket')
        ->name('pos.sales.ticket')
        ->middleware('permission:sale.get|pos.checkout');
    Route::get('/pos/sales/{saleId}/ticket/html', 'printTicket')
        ->name('pos.sales.ticket.html')
        ->middleware('permission:sale.get|pos.checkout');
});
