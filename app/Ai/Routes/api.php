<?php

use App\Ai\Controllers\AiPredictionController;
use Illuminate\Support\Facades\Route;

Route::controller(AiPredictionController::class)->group(function (): void {
    Route::get('/ai/products/{product}/context', 'productContext')
        ->middleware('permission:product.get|product.getAll');

    Route::post('/ai/predict/price', 'predictPrice')
        ->middleware('permission:product.get|product.getAll');

    Route::post('/ai/predict/demand', 'predictDemand')
        ->middleware('permission:product.get|product.getAll');
});
