<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Product\Models\ProductSize;

class ProductSizeColorService
{
    public function set(ProductSize $productSize, int $colorId, array $data): void
    {
        $productSize->productSizeColors()->syncWithoutDetaching([
            $colorId => ['stock' => $data['stock']]
        ]);
    }

    public function remove(ProductSize $productSize, int $colorId): void
    {
        $productSize->productSizeColors()->detach($colorId);
    }

    public function exists(ProductSize $productSize, int $colorId): bool
    {
        return $productSize->productSizeColors()
            ->wherePivot('color_id', $colorId)
            ->exists();
    }
}
