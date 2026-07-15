<?php

namespace App\Inventory\Product\Services;

use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Models\InventoryMovement;
use App\Inventory\Product\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReconciliationPosSalesService
{
    /**
     * Unidades netas vendidas por POS (movimientos SALE) desde una fecha, por variante.
     *
     * @return array{
     *     since: string,
     *     sinceLabel: string,
     *     variants: list<array{
     *         productSizeId: int,
     *         sizeId: int,
     *         colorId: int|null,
     *         quantitySold: int,
     *         saleCount: int,
     *         lastSoldAt: string|null
     *     }>,
     *     totalSold: int,
     *     hasAnySales: bool
     * }
     */
    public function summarizeForProduct(Product $product, ?CarbonImmutable $since = null): array
    {
        $since ??= CarbonImmutable::parse(
            (string) config('inventory.physical_count_started_at', '2026-07-10 00:00:00'),
        );

        $warehouseId = (int) ($product->warehouse_id ?? 0);
        if ($warehouseId < 1) {
            return $this->emptySummary($since);
        }

        $rows = InventoryMovement::query()
            ->select([
                'inventory_movements.product_size_id',
                'inventory_movements.color_id',
                DB::raw("SUM(CASE WHEN inventory_movements.direction = '".InventoryMovementDirection::Out->value."' THEN inventory_movements.quantity ELSE -inventory_movements.quantity END) as net_sold"),
                DB::raw('COUNT(DISTINCT inventory_movements.reference_id) as sale_count'),
                DB::raw('MAX(inventory_movements.occurred_at) as last_sold_at'),
            ])
            ->join('product_size', 'product_size.id', '=', 'inventory_movements.product_size_id')
            ->where('product_size.product_id', $product->id)
            ->where('inventory_movements.warehouse_id', $warehouseId)
            ->where('inventory_movements.movement_type', InventoryMovementType::Sale->value)
            ->where('inventory_movements.reference_type', Sale::class)
            ->where('inventory_movements.occurred_at', '>=', $since)
            ->groupBy('inventory_movements.product_size_id', 'inventory_movements.color_id')
            ->get();

        $sizeIdsByProductSizeId = $this->loadSizeIdsByProductSizeId(
            $rows->pluck('product_size_id')->map(static fn ($id): int => (int) $id)->unique()->values(),
        );

        $variants = $rows
            ->map(function ($row) use ($sizeIdsByProductSizeId): ?array {
                $netSold = (int) $row->net_sold;
                if ($netSold < 1) {
                    return null;
                }

                $productSizeId = (int) $row->product_size_id;
                $colorId = $row->color_id !== null ? (int) $row->color_id : null;
                $lastSoldAt = $row->last_sold_at;

                return [
                    'productSizeId' => $productSizeId,
                    'sizeId' => $sizeIdsByProductSizeId[$productSizeId] ?? 0,
                    'colorId' => $colorId,
                    'quantitySold' => $netSold,
                    'saleCount' => (int) $row->sale_count,
                    'lastSoldAt' => $lastSoldAt !== null ? (string) $lastSoldAt : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $totalSold = array_sum(array_map(static fn (array $v): int => $v['quantitySold'], $variants));

        return [
            'since' => $since->toIso8601String(),
            'sinceLabel' => $since->format('d/m/Y'),
            'variants' => $variants,
            'totalSold' => $totalSold,
            'hasAnySales' => $totalSold > 0,
        ];
    }

    /**
     * @param  Collection<int, int>  $productSizeIds
     * @return array<int, int>
     */
    private function loadSizeIdsByProductSizeId(Collection $productSizeIds): array
    {
        if ($productSizeIds->isEmpty()) {
            return [];
        }

        return DB::table('product_size')
            ->whereIn('id', $productSizeIds->all())
            ->pluck('size_id', 'id')
            ->map(static fn ($sizeId): int => (int) $sizeId)
            ->all();
    }

    /**
     * @return array{
     *     since: string,
     *     sinceLabel: string,
     *     variants: list<never>,
     *     totalSold: int,
     *     hasAnySales: bool
     * }
     */
    private function emptySummary(CarbonImmutable $since): array
    {
        return [
            'since' => $since->toIso8601String(),
            'sinceLabel' => $since->format('d/m/Y'),
            'variants' => [],
            'totalSold' => 0,
            'hasAnySales' => false,
        ];
    }
}
