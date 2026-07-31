<?php

use App\Ecommerce\Multimedia\Controllers\ProductMediaController;
use Illuminate\Support\Facades\Route;

Route::controller(ProductMediaController::class)->group(function (): void {
    Route::get('/products/{product}/media/{media}/preview', 'preview')
        ->middleware('permission:product.get');
    Route::post('/products/{product}/media', 'store')->middleware('permission:product.update');
    Route::delete('/products/{product}/media/{media}', 'destroy')->middleware('permission:product.update');
});
