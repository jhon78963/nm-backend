<?php

use App\Inventories\Reconciliations\Controllers\InventoryReconciliationController;
use Illuminate\Support\Facades\Route;

Route::controller(InventoryReconciliationController::class)->group(function (): void {
    Route::get('/inventory/reconciliation/search', 'search')
        ->middleware('permission:inventoryReconciliation.search');
    Route::get('/inventory/reconciliation/{product}/pos-sales', 'posSalesSince')
        ->middleware('permission:inventoryReconciliation.search');
    Route::put('/inventory/reconciliation/{product}', 'update')
        ->middleware('permission:inventoryReconciliation.update');
    Route::post(
        '/inventory/reconciliation/{product}/product-size/{productSize}/replace-color',
        'replaceColor',
    )->middleware('permission:inventoryReconciliation.replaceColor');
});
