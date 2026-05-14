<?php

namespace App\Inventory\InventoryLedger\Support;

use App\Inventory\InventoryLedger\Models\InventoryBalance;

final class InventoryBalanceLookup
{
    public static function key(int $productSizeId, ?int $colorId): string
    {
        return $productSizeId.':'.($colorId === null ? '0' : $colorId);
    }

    public static function quantityFor(int $warehouseId, int $productSizeId, ?int $colorId): int
    {
        if ($warehouseId < 1) {
            return 0;
        }

        $q = InventoryBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_size_id', $productSizeId);

        if ($colorId === null) {
            $q->whereNull('color_id');
        } else {
            $q->where('color_id', $colorId);
        }

        return (int) ($q->value('quantity') ?? 0);
    }

    public static function sumQuantityForProduct(int $productId, int $warehouseId): int
    {
        $q = InventoryBalance::query()->where('product_id', $productId);
        if ($warehouseId > 0) {
            $q->where('warehouse_id', $warehouseId);
        }

        return (int) $q->sum('quantity');
    }

    /**
     * @return array<string, int>
     */
    public static function mapForProductWarehouse(int $productId, int $warehouseId): array
    {
        if ($warehouseId < 1) {
            return [];
        }

        $rows = InventoryBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->get(['product_size_id', 'color_id', 'quantity']);

        $out = [];
        foreach ($rows as $row) {
            $colorId = $row->color_id !== null ? (int) $row->color_id : null;
            $out[self::key((int) $row->product_size_id, $colorId)] = (int) $row->quantity;
        }

        return $out;
    }
}
