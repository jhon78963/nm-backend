<?php

namespace App\Ai\Services;

use App\Inventories\Kardex\Support\InventoryBalanceLookup;
use App\Inventories\Kardex\Support\WarehouseIdForInventoryResolver;
use App\Inventories\Products\Models\Product;
use App\Inventories\Products\Support\PurchasePriceVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiProductContextService
{
    /**
     * @return array{
     *     productId: int,
     *     productName: string,
     *     currentCost: float,
     *     category: string,
     *     salesLastMonth: int,
     *     currentStock: int,
     *     salePrice: float,
     *     canViewCost: bool
     * }
     */
    public function resolve(Product $product, Request $request): array
    {
        $product->load([
            'gender',
            'productSizes' => static fn ($query) => $query->orderBy('id'),
        ]);

        $warehouseId = WarehouseIdForInventoryResolver::resolve(
            $request,
            $product->warehouse_id !== null ? (int) $product->warehouse_id : null,
        );

        $primaryProductSize = $product->productSizes->first();
        $canViewCost = PurchasePriceVisibility::canView($request);
        $currentCost = $canViewCost && $primaryProductSize !== null
            ? (float) ($primaryProductSize->purchase_price ?? 0)
            : 0.0;

        $salePrice = $primaryProductSize !== null
            ? (float) ($primaryProductSize->sale_price ?? 0)
            : 0.0;

        return [
            'productId' => (int) $product->id,
            'productName' => (string) ($product->name ?? ''),
            'currentCost' => $currentCost,
            'category' => $product->gender?->name ?? 'Sin categoría',
            'salesLastMonth' => $this->salesLastMonthQuantity((int) $product->id, $warehouseId),
            'currentStock' => InventoryBalanceLookup::sumQuantityForProduct((int) $product->id, $warehouseId),
            'salePrice' => $salePrice,
            'canViewCost' => $canViewCost,
        ];
    }

    /**
     * @return array{
     *     product_id: int,
     *     current_cost: float,
     *     current_sale_price: float,
     *     category: string,
     *     sales_last_month: int
     * }
     */
    public function buildPricePayload(Product $product, Request $request): array
    {
        $context = $this->resolve($product, $request);

        if (! $context['canViewCost']) {
            throw new RuntimeException('No tienes permiso para ver el costo de compra de este producto.');
        }

        if ($context['currentCost'] <= 0) {
            throw new RuntimeException('El producto no tiene costo de compra registrado.');
        }

        if ($context['category'] === 'Sin categoría') {
            throw new RuntimeException('El producto no tiene categoría (género) asignada.');
        }

        return [
            'product_id' => $context['productId'],
            'current_cost' => $context['currentCost'],
            'current_sale_price' => $context['salePrice'],
            'category' => $context['category'],
            'sales_last_month' => $context['salesLastMonth'],
        ];
    }

    /**
     * @return array{product_id: int, current_stock: int, horizon_days: int}
     */
    public function buildDemandPayload(Product $product, Request $request, int $horizonDays = 30): array
    {
        $context = $this->resolve($product, $request);

        return [
            'product_id' => $context['productId'],
            'current_stock' => $context['currentStock'],
            'horizon_days' => $horizonDays,
        ];
    }

    private function salesLastMonthQuantity(int $productId, int $warehouseId): int
    {
        $start = now()->subDays(30)->startOfDay();
        $end = now()->endOfDay();

        $query = DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->where('sd.product_id', $productId)
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->whereBetween('s.creation_time', [$start, $end]);

        if ($warehouseId > 0) {
            $query->where('s.warehouse_id', $warehouseId);
        }

        return (int) $query->sum('sd.quantity');
    }
}
