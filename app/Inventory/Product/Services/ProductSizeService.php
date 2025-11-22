<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\Product;

class ProductSizeService
{
    public function set(Product $product, int $sizeId, array $data): void {
        $pivotData = [
            'barcode'        => $data['barcode'],
            'stock'          => $data['stock'],
            'purchase_price' => $data['purchasePrice'],
            'sale_price'     => $data['salePrice'],
            'min_sale_price' => $data['minSalePrice'],
        ];

        $product->sizes()->syncWithoutDetaching([
            $sizeId => $pivotData
        ]);
    }

    public function remove(Product $product, int $sizeId): void
    {
        $product->sizes()->detach($sizeId);
    }
}
