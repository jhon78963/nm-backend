<?php

use App\Inventory\Purchase\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::controller(PurchaseController::class)->group(function (): void {
    Route::post('/purchases/bulk', 'registerBulk');
    Route::get('/purchases', 'getAll');
    Route::patch('/purchases/{purchase}/lines/{purchaseLine}', 'updateLine');
    Route::delete('/purchases/{purchase}/lines/{purchaseLine}', 'deleteLine');
    Route::get('/purchases/{purchase}', 'get');
    Route::patch('/purchases/{purchase}', 'update');
    Route::post('/purchases/{purchase}/cancel', 'cancel');
});
