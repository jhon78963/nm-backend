<?php

use App\Inventory\Purchase\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::controller(PurchaseController::class)->group(function (): void {
    Route::post('/purchases/bulk', 'registerBulk');
});
