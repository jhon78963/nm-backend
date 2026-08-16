<?php

use App\Ai\Controllers\AiPredictionController;
use App\Ai\Controllers\AiReportController;
use Illuminate\Support\Facades\Route;

Route::controller(AiPredictionController::class)->group(function (): void {
    Route::get('/ai/products/{product}/context', 'productContext')
        ->middleware('permission:product.get|product.getAll');

    Route::post('/ai/predict/price', 'predictPrice')
        ->middleware('permission:product.get|product.getAll');

    Route::post('/ai/predict/demand', 'predictDemand')
        ->middleware('permission:product.get|product.getAll');
});

Route::controller(AiReportController::class)->group(function (): void {
    Route::get('/ai/reports/products-inventory', 'productsInventory')
        ->middleware('permission:report.products');
});
