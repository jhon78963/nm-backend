<?php

use App\Sales\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::controller(SaleController::class)->group(function () {
    Route::get('/pos/products', 'searchProduct');
    Route::get('/pos/customers', 'searchCustomer');
    Route::post('/pos/checkout', 'checkout');
    Route::get('/pos/sales/{saleId}/ticket/base64', 'getTicketBase64');
    Route::get('/pos/sales/{saleId}/ticket/html', [SaleController::class, 'getTicketHtml']);
});
