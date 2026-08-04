<?php

namespace App\Ai\Services;

use App\Ai\Support\DeadStockEvaluator;
use App\Inventories\Kardex\Support\InventoryBalanceLookup;
use App\Inventories\Kardex\Support\WarehouseIdForInventoryResolver;
use App\Inventories\Products\Models\Product;
use App\Inventories\Products\Support\PurchasePriceVisibility;
use Carbon\Carbon;
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
     *     canViewCost: bool,
     *     productAgeDays: int,
     *     daysSinceLastSale: int,
     *     totalSalesAllTime: int,
     *     isDeadStock: bool,
     *     deadStockTier: string,
     *     deadStockLabel: string
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

        $productId = (int) $product->id;
        $salesLastMonth = $this->salesLastMonthQuantity($productId, $warehouseId);
        $currentStock = InventoryBalanceLookup::sumQuantityForProduct($productId, $warehouseId);
        $productAgeDays = $this->productAgeDays($product);
        $totalSalesAllTime = $this->totalSalesAllTime($productId, $warehouseId);
        $daysSinceLastSale = $this->daysSinceLastSale($productId, $warehouseId, $productAgeDays);

        $deadStock = DeadStockEvaluator::evaluate(
            $productAgeDays,
            $daysSinceLastSale,
            $salesLastMonth,
            $currentStock,
            $totalSalesAllTime,
        );

        return [
            'productId' => $productId,
            'productName' => (string) ($product->name ?? ''),
            'currentCost' => $currentCost,
            'category' => $product->gender?->name ?? 'Sin categoría',
            'salesLastMonth' => $salesLastMonth,
            'currentStock' => $currentStock,
            'salePrice' => $salePrice,
            'canViewCost' => $canViewCost,
            'productAgeDays' => $productAgeDays,
            'daysSinceLastSale' => $daysSinceLastSale,
            'totalSalesAllTime' => $totalSalesAllTime,
            'isDeadStock' => $deadStock['isDeadStock'],
            'deadStockTier' => $deadStock['tier'],
            'deadStockLabel' => $deadStock['label'],
        ];
    }

    /**
     * @return array{
     *     product_id: int,
     *     current_cost: float,
     *     current_sale_price: float,
     *     category: string,
     *     sales_last_month: int,
     *     current_stock: int,
     *     product_age_days: int,
     *     days_since_last_sale: int,
     *     total_sales_all_time: int
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
            'current_stock' => $context['currentStock'],
            'product_age_days' => $context['productAgeDays'],
            'days_since_last_sale' => $context['daysSinceLastSale'],
            'total_sales_all_time' => $context['totalSalesAllTime'],
        ];
    }

    /**
     * @return array{
     *     product_id: int,
     *     current_stock: int,
     *     horizon_days: int,
     *     sales_last_month: int,
     *     product_age_days: int,
     *     days_since_last_sale: int,
     *     total_sales_all_time: int
     * }
     */
    public function buildDemandPayload(Product $product, Request $request, int $horizonDays = 30): array
    {
        $context = $this->resolve($product, $request);

        return [
            'product_id' => $context['productId'],
            'current_stock' => $context['currentStock'],
            'horizon_days' => $horizonDays,
            'sales_last_month' => $context['salesLastMonth'],
            'product_age_days' => $context['productAgeDays'],
            'days_since_last_sale' => $context['daysSinceLastSale'],
            'total_sales_all_time' => $context['totalSalesAllTime'],
        ];
    }

    private function productAgeDays(Product $product): int
    {
        $created = $product->creation_time;
        if ($created === null) {
            return 0;
        }

        return (int) max(0, Carbon::parse($created)->diffInDays(now()));
    }

    private function daysSinceLastSale(int $productId, int $warehouseId, int $productAgeDays): int
    {
        $query = DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->where('sd.product_id', $productId)
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false);

        if ($warehouseId > 0) {
            $query->where('s.warehouse_id', $warehouseId);
        }

        $lastSaleAt = $query->max('s.creation_time');
        if ($lastSaleAt === null) {
            return $productAgeDays;
        }

        return (int) max(0, Carbon::parse($lastSaleAt)->diffInDays(now()));
    }

    private function totalSalesAllTime(int $productId, int $warehouseId): int
    {
        $query = DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->where('sd.product_id', $productId)
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false);

        if ($warehouseId > 0) {
            $query->where('s.warehouse_id', $warehouseId);
        }

        return (int) $query->sum('sd.quantity');
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
