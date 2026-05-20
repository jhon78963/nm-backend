<?php

use App\Inventory\Purchase\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::controller(PurchaseController::class)->group(function (): void {
    Route::post('/purchases/bulk', 'registerBulk')->middleware('permission:purchase.registerBulk');
    Route::get('/purchases', 'getAll')->middleware('permission:purchase.getAll');
    Route::patch('/purchases/{purchase}/lines/{purchaseLine}', 'updateLine')->middleware('permission:purchase.updateLine');
    Route::delete('/purchases/{purchase}/lines/{purchaseLine}', 'deleteLine')->middleware('permission:purchase.deleteLine');
    Route::get('/purchases/{purchase}', 'get')->middleware('permission:purchase.get');
    Route::patch('/purchases/{purchase}', 'update')->middleware('permission:purchase.update');
    Route::post('/purchases/{purchase}/cancel', 'cancel')->middleware('permission:purchase.cancel');
});
