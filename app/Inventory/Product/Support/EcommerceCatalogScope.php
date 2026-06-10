<?php

namespace App\Inventory\Product\Support;

use App\Inventory\Product\Models\Product;
use Illuminate\Database\Eloquent\Builder;

trait EcommerceCatalogScope
{
    /**
     * @return Builder<Product>
     */
    protected function ecommerceProductQuery(int $warehouseId): Builder
    {
        return Product::query()
            ->where('is_deleted', false)
            ->where('warehouse_id', $warehouseId);
    }

    /**
     * @return array<int, string|\Closure>
     */
    protected function ecommerceWith(int $warehouseId): array
    {
        return [
            'productSizes.size',
            'productSizes.colors',
            'gender',
            'media',
            'inventoryBalances' => static fn ($query) => $query->where('warehouse_id', $warehouseId),
        ];
    }
}
