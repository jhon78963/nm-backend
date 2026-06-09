<?php

namespace App\Inventory\Product\Resources;

use App\Inventory\InventoryLedger\Support\InventoryBalanceLookup;
use App\Inventory\WooCommerce\Support\ProductMediaUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Catálogo público: solo datos de vitrina (sin IDs internos ni costos de compra).
 */
class ProductEcommerceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variations = [];
        $colorNames = [];
        $sizeNames = [];
        $totalStock = 0;
        $balanceMap = $this->balanceMapForWarehouse();

        $minSalePrice = (float) ($this->productSizes->min('sale_price') ?? 0);

        foreach ($this->productSizes as $productSize) {
            $sizeLabel = $productSize->size->description ?? '';
            if ($sizeLabel !== '' && ! in_array($sizeLabel, $sizeNames, true)) {
                $sizeNames[] = $sizeLabel;
            }

            foreach ($productSize->colors as $color) {
                $stock = $this->stockForVariant((int) $productSize->id, (int) $color->id, $balanceMap);
                $totalStock += $stock;

                $colorLabel = $color->description ?? '';
                if ($colorLabel !== '' && ! isset($colorNames[$colorLabel])) {
                    $colorNames[$colorLabel] = $color->hash ?? null;
                }

                $variantSalePrice = (float) ($productSize->sale_price ?? $minSalePrice);
                $variantSku = $productSize->barcode ?? $this->barcode;

                $variations[] = [
                    'sku' => $variantSku,
                    'name' => "{$this->name} ({$colorLabel} / {$sizeLabel})",
                    'price' => $variantSalePrice,
                    'in_stock' => $stock > 0,
                    'stock_quantity' => $stock,
                    'color' => $colorLabel,
                    'size' => $sizeLabel,
                ];
            }
        }

        $slug = Str::slug($this->name.'-'.$this->id);

        /** @var ProductMediaUrlResolver $mediaUrlResolver */
        $mediaUrlResolver = app(ProductMediaUrlResolver::class);
        $gallery = $mediaUrlResolver->galleryUrlsForProduct($this->resource);

        return [
            'slug' => $slug,
            'name' => $this->name,
            'description' => strip_tags($this->description ?? ''),
            'short_description' => Str::limit(strip_tags($this->description ?? ''), 100),
            'price' => $minSalePrice,
            'sku' => $this->barcode,
            'in_stock' => $totalStock > 0,
            'stock_quantity' => $totalStock,
            'available' => $this->status === 'AVAILABLE',
            'discount_percent' => $this->percentage_discount,

            'thumbnail' => $gallery[0] ?? null,
            'gallery' => $gallery,

            'colors' => collect($colorNames)->map(static fn (?string $hex, string $name) => [
                'name' => $name,
                'hex' => $hex,
            ])->values(),

            'sizes' => $sizeNames,

            'variants' => $variations,
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
