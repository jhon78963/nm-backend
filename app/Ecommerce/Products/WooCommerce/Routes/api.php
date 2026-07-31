<?php

use App\Ecommerce\Products\WooCommerce\Controllers\ProductWooCommerceController;
use Illuminate\Support\Facades\Route;

Route::post('/products/{product}/woocommerce/sync', [ProductWooCommerceController::class, 'sync'])
    ->middleware('permission:product.update');
