<?php

use App\Ai\Controllers\AiPredictionController;
use Illuminate\Support\Facades\Route;

Route::controller(AiPredictionController::class)->group(function (): void {
    Route::get('/ai/products/{product}/context', 'productContext')
        ->middleware('permission:product.get');
    Route::post('/ai/predict/price', 'optimizePrice')
        ->middleware('permission:product.get');
    Route::post('/ai/predict/demand', 'predictDemand')
        ->middleware('permission:product.get');
    Route::get('/ai/reports/products-inventory', 'productsInventoryReport')
        ->middleware('permission:report.products|product.get');
});
