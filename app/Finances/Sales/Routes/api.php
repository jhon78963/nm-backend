<?php

use App\Finances\Sales\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::controller(SaleController::class)->group(function (): void {
    Route::patch('/sales/{sale}', 'update')->middleware('permission:sale.update');
    Route::delete('/sales/{sale}', 'delete')->middleware('permission:sale.delete');
    Route::get('/sales', 'getAll')->middleware('permission:sale.getAll');
    Route::get('/sales/monthly-stats', 'getMonthlyStats')->middleware('permission:sale.getMonthlyStats');
    Route::get('/sales/{sale}/pdf', 'downloadPdf')->middleware('permission:sale.get');
    Route::get('/sales/{sale}', 'get')->middleware('permission:sale.get');
    Route::post('/sales/exchange', 'exchange')->middleware('permission:sale.exchange');
});
