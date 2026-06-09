<?php

namespace App\Inventory\WooCommerce\Support;

final class WooCommerceSyncMapKey
{
    public static function make(int $productId, int $productSizeId, int $colorId): string
    {
        return "{$productId}:{$productSizeId}:{$colorId}";
    }
}
