<?php

use App\Inventory\InventoryLedger\Controllers\InventoryKardexController;
use Illuminate\Support\Facades\Route;

Route::get('/inventory/kardex', [InventoryKardexController::class, 'index']);
