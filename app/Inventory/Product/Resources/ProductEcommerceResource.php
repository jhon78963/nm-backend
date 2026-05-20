<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductEcommerceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variations = [];
        $uniqueColors = [];
        $uniqueSizes = [];
        $totalStock = 0;
        $balanceMap = $this->balanceMapForWarehouse();

        $minPrice = $this->productSizes->min('sale_price') ?? 0;

        foreach ($this->productSizes as $productSize) {
            if (! isset($uniqueSizes[$productSize->size_id])) {
                $uniqueSizes[$productSize->size_id] = [
                    'id' => $productSize->size_id,
                    'value' => $productSize->size->description ?? '',
                    'attribute_id' => 14,
                ];
            }

            foreach ($productSize->colors as $color) {
                $stock = $this->stockForVariant((int) $productSize->id, (int) $color->id, $balanceMap);
                $totalStock += $stock;

                if (! isset($uniqueColors[$color->id])) {
                    $uniqueColors[$color->id] = [
                        'id' => $color->id,
                        'value' => $color->description,
                        'hex_color' => $color->hash,
                        'attribute_id' => 13,
                    ];
                }

                $variations[] = [
                    'id' => $productSize->id.'-'.$color->id,
                    'name' => "{$this->name} ({$color->description} / {$productSize->size->description})",
                    'price' => $productSize->purchase_price ?? $minPrice,
                    'sale_price' => $productSize->sale_price ?? $minPrice,
                    'quantity' => $stock,
                    'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                    'sku' => $productSize->barcode ?? $this->barcode,
                    'attribute_values' => [
                        ['id' => $color->id, 'value' => $color->description, 'attribute_id' => 13],
                        ['id' => $productSize->size_id, 'value' => $productSize->size->description, 'attribute_id' => 14],
                    ],
                ];
            }
        }

        $imageServerUrl = rtrim(env('IMAGE_SERVER_URL', 'http://localhost:8001'), '/');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => '<p>'.($this->description ?? '').'</p>',
            'price' => $minPrice,
            'sale_price' => $minPrice,
            'quantity' => $totalStock,
            'sku' => $this->barcode,
            'status' => $this->status === 'AVAILABLE' ? 1 : 0,
            'stock_status' => $totalStock > 0 ? 'in_stock' : 'out_of_stock',
            'discount' => $this->percentage_discount,

            'product_thumbnail' => $this->imagesEcommerce->first() ? [
                'id' => $this->imagesEcommerce->first()->path,
                'name' => $this->imagesEcommerce->first()->name,
                'asset_url' => $imageServerUrl.'/storage/'.ltrim($this->imagesEcommerce->first()->path, '/'),
            ] : null,

            'product_galleries' => $this->imagesEcommerce->map(function ($img) use ($imageServerUrl) {
                return [
                    'name' => $img->name,
                    'asset_url' => $imageServerUrl.'/storage/'.ltrim($img->path, '/'),
                ];
            }),

            'slug' => Str::slug($this->name.'-'.$this->id),
            'short_description' => Str::limit(strip_tags($this->description ?? ''), 100),

            'wholesales' => [],
            'cross_sell_products' => [],
            'related_products' => [],
            'cross_products' => [],
            'similar_products' => [],

            'unit' => '1 Unidad',
            'weight' => 0,
            'created_at' => $this->creation_time ?? now()->toIso8601String(),
            'updated_at' => $this->last_modification_time ?? now()->toIso8601String(),

            'orders_count' => 0,
            'reviews_count' => 0,
            'rating_count' => 0,
            'can_review' => false,
            'review_ratings' => [0, 0, 0, 0, 0],

            'store_id' => 1,
            'store' => [
                'id' => 1,
                'store_name' => 'Novedades Maritex',
                'slug' => 'novedades-maritex',
            ],

            'categories' => [],
            'brand' => null,
            'tags' => [],
            'reviews' => [],

            'is_featured' => 1,
            'is_trending' => 0,
            'is_return' => 0,
            'is_approved' => 1,
            'type' => 'simple',

            'attributes' => [
                [
                    'id' => 13,
                    'name' => 'Colour',
                    'attribute_values' => array_values($uniqueColors),
                ],
                [
                    'id' => 14,
                    'name' => 'Size',
                    'attribute_values' => array_values($uniqueSizes),
                ],
            ],
            'variations' => $variations,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function balanceMapForWarehouse(): array
    {
        $warehouseId = (int) ($this->warehouse_id ?? 0);
        if ($warehouseId < 1) {
            return [];
        }

        if ($this->relationLoaded('inventoryBalances')) {
            $out = [];
            foreach ($this->inventoryBalances as $balance) {
                if ((int) $balance->warehouse_id !== $warehouseId) {
                    continue;
                }

                $colorId = $balance->color_id !== null ? (int) $balance->color_id : null;
                $out[InventoryBalanceLookup::key((int) $balance->product_size_id, $colorId)] = (int) $balance->quantity;
            }

            return $out;
        }

        return InventoryBalanceLookup::mapForProductWarehouse((int) $this->id, $warehouseId);
    }

    /**
     * @param  array<string, int>  $balanceMap
     */
    private function stockForVariant(int $productSizeId, int $colorId, array $balanceMap): int
    {
        return $balanceMap[InventoryBalanceLookup::key($productSizeId, $colorId)] ?? 0;
    }
}
