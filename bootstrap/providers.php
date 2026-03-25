<?php

return [
    App\Auth\Providers\TokenServiceProvider::class,
    App\Inventory\Product\Models\ProductHistory::observe( App\Inventory\Product\Observers\ProductHistoryObserver::class),
];
