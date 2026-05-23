<?php

use App\Finance\Sale\Controllers\PosController;
use App\Finance\Sale\Controllers\SaleController;
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

Route::controller(SaleController::class)->group(function (): void {
    Route::patch('/sales/{sale}', 'update')->middleware('permission:sale.update');
    Route::delete('/sales/{sale}', 'delete')->middleware('permission:sale.delete');
    Route::get('/sales', 'getAll')->middleware('permission:sale.getAll');
    Route::get('/sales/monthly-stats', 'getMonthlyStats')->middleware('permission:sale.getMonthlyStats');
    Route::get('/sales/{sale}', 'get')->middleware('permission:sale.get');
    Route::post('/sales/exchange', 'exchange')->middleware('permission:sale.exchange');
});
