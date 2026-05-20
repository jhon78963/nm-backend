<?php

namespace App\Inventory\Product\Support;

use App\Inventory\Product\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

trait EcommerceCatalogScope
{
    protected function ecommerceWarehouseId(): int
    {
        $warehouseId = config('ecommerce.warehouse_id');

        if ($warehouseId === null || (int) $warehouseId < 1) {
            throw new ServiceUnavailableHttpException(
                null,
                'Catálogo temporalmente no disponible.',
            );
        }

        return (int) $warehouseId;
    }

    /**
     * @return Builder<Product>
     */
    protected function ecommerceProductQuery(): Builder
    {
        $warehouseId = $this->ecommerceWarehouseId();

        return Product::query()
            ->where('is_deleted', false)
            ->where('warehouse_id', $warehouseId);
    }

    /**
     * @return array<int, string>
     */
    protected function ecommerceWith(int $warehouseId): array
    {
        return [
            'productSizes.size',
            'productSizes.colors',
            'imagesEcommerce',
            'inventoryBalances' => static fn ($query) => $query->where('warehouse_id', $warehouseId),
        ];
    }
}
