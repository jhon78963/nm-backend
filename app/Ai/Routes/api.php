<?php

use App\Ai\Controllers\AiPredictionController;
use Illuminate\Support\Facades\Route;

Route::controller(AiPredictionController::class)->group(function (): void {
    Route::post('/ai/predict/price', 'optimizePrice');
    Route::post('/ai/predict/demand', 'predictDemand');
});
