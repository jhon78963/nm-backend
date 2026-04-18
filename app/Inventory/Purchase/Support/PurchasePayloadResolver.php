<?php

namespace App\Inventory\Purchase\Support;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use InvalidArgumentException;

/**
 * Resuelve referencias del JSON de compra masiva (ids vs tempIds).
 */
class PurchasePayloadResolver
{
    /**
     * @param  array<string, mixed>  $ref
     * @param  array<string, int>  $tempMap
     */
    public function resolveProductId(array $ref, array $tempMap): int
    {
        if (($ref['mode'] ?? '') === 'id') {
            return (int) $ref['productId'];
        }
        $tid = (string) ($ref['tempId'] ?? '');
        if ($tid === '' || ! isset($tempMap[$tid])) {
            throw new InvalidArgumentException('Producto temporal no resuelto: '.$tid);
        }

        return $tempMap[$tid];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  array<string, int>  $tempMap
     */
    public function resolveSizeId(array $ref, array $tempMap): int
    {
        if (($ref['mode'] ?? '') === 'id') {
            return (int) $ref['sizeId'];
        }
        $tid = (string) ($ref['tempId'] ?? '');
        if ($tid === '' || ! isset($tempMap[$tid])) {
            throw new InvalidArgumentException('Talla temporal no resuelta: '.$tid);
        }

        return $tempMap[$tid];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function resolveProductSize(Product $product, int $sizeId, array $line): ProductSize
    {
        $psId = $line['productSizeId'] ?? null;
        if ($psId) {
            $ps = ProductSize::query()
                ->where('id', $psId)
                ->where('product_id', $product->id)
                ->where('size_id', $sizeId)
                ->first();
            if ($ps) {
                return $ps;
            }
        }

        return ProductSize::query()->firstOrCreate(
            [
                'product_id' => $product->id,
                'size_id' => $sizeId,
            ],
            [
                'barcode' => null,
                'stock' => 0,
                'purchase_price' => null,
                'sale_price' => null,
                'min_sale_price' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{colorId: ?int, tempId: ?string, quantity: int}
     */
    public function normalizeLineColorRow(array $c): array
    {
        $colorId = $c['colorId'] ?? $c['color_id'] ?? null;
        $tempId = $c['tempId'] ?? $c['temp_id'] ?? null;

        return [
            'colorId' => $colorId !== null && $colorId !== '' ? (int) $colorId : null,
            'tempId' => $tempId !== null && $tempId !== '' ? (string) $tempId : null,
            'quantity' => max(0, (int) ($c['quantity'] ?? 0)),
        ];
    }

    /**
     * @param  array<int, array{colorId: ?int, tempId: ?string, quantity: int}>  $colors
     */
    public function colorsHaveIds(array $colors): bool
    {
        foreach ($colors as $c) {
            if ($c['colorId'] !== null || $c['tempId'] !== null) {
                return true;
            }
        }

        return false;
    }
}
