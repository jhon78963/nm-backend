<?php

namespace App\Inventory\Product\Observers;

// use App\Inventory\Product\Jobs\SyncSingleProductToMultikartJob;
// use App\Inventory\Product\Models\ProductHistory;

class ProductHistoryObserver
{
    /**
     * Handle the ProductHistory "created" event.
     */
    public function created(ProductHistory $productHistory): void
    {
       // SyncSingleProductToMultikartJob::dispatch($productHistory->product_id);
    }
}
