<?php

namespace App\Inventory\Product\Providers;

use App\Inventory\Product\Models\ProductHistory;
use App\Inventory\Product\Observers\ProductHistoryObserver;
use Illuminate\Support\ServiceProvider;

class ProductHistoryProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProductHistory::observe(ProductHistoryObserver::class);
    }
}
