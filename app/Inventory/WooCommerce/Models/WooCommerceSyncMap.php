<?php

namespace App\Inventory\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;

class WooCommerceSyncMap extends Model
{
    protected $fillable = [
        'product_id',
        'product_size_id',
        'color_id',
        'woo_product_id',
        'woo_variation_id',
        'variant_key',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public static function variantKey(int $productId, int $productSizeId, int $colorId): string
    {
        return "{$productId}:{$productSizeId}:{$colorId}";
    }
}
