<?php

namespace App\Dashboard\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use App\Inventory\Purchase\Models\Purchase;
use App\Shared\Foundation\Support\WarehouseQueryFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    /**
     * @return array{
     *     todaySales: int,
     *     todaySalesAmount: float,
     *     todayExpenses: float,
     *     lowStockProducts: int,
     *     pendingPurchases: int,
     *     activeCustomers: int
     * }
     */
    public function getMetrics(): array
    {
        $todayStart = Carbon::now()->startOfDay();
        $todayEnd = Carbon::now()->endOfDay();
        $monthStart = Carbon::now()->startOfMonth()->startOfDay();
        $monthEnd = Carbon::now()->endOfMonth()->endOfDay();

        $salesQuery = Sale::query()
            ->whereBetween('creation_time', [$todayStart, $todayEnd])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false);

        WarehouseQueryFilter::apply($salesQuery, 'warehouse_id');

        $todaySales = (int) (clone $salesQuery)->count();
        $todaySalesAmount = round((float) (clone $salesQuery)->sum('total_amount'), 2);

        $expensesQuery = CashMovement::query()
            ->whereBetween('date', [$todayStart, $todayEnd])
            ->operatingExpenses()
            ->where('is_deleted', false);

        WarehouseQueryFilter::apply($expensesQuery, 'warehouse_id');

        $todayExpenses = round((float) $expensesQuery->sum('amount'), 2);

        $activeCustomersQuery = Sale::query()
            ->whereBetween('creation_time', [$monthStart, $monthEnd])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->whereNotNull('customer_id');

        WarehouseQueryFilter::apply($activeCustomersQuery, 'warehouse_id');

        $activeCustomers = (int) $activeCustomersQuery
            ->distinct()
            ->count('customer_id');

        return [
            'todaySales' => $todaySales,
            'todaySalesAmount' => $todaySalesAmount,
            'todayExpenses' => $todayExpenses,
            'lowStockProducts' => $this->countLowStockProducts(),
            'pendingPurchases' => $this->countPendingPurchases(),
            'activeCustomers' => $activeCustomers,
        ];
    }

    private function countLowStockProducts(): int
    {
        $warehouseId = WarehouseQueryFilter::resolveWarehouseId();

        if ($warehouseId <= 0) {
            return 0;
        }

        return (int) DB::table('inventory_balances as ib')
            ->join('product_size as ps', 'ps.id', '=', 'ib.product_size_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('ib.warehouse_id', $warehouseId)
            ->where('p.is_deleted', false)
            ->groupBy('p.id')
            ->havingRaw('SUM(ib.quantity) > 0 AND SUM(ib.quantity) < 5')
            ->select('p.id')
            ->get()
            ->count();
    }

    private function countPendingPurchases(): int
    {
        $query = Purchase::query()
            ->where('status', PurchaseStatus::Active)
            ->where('is_deleted', false)
            ->whereDoesntHave('cashMovements', function ($builder): void {
                $builder
                    ->where('type', CashMovement::TYPE_EXPENSE)
                    ->where('category', CashMovement::CATEGORY_INVENTORY_PURCHASE)
                    ->where('is_deleted', false);
            });

        WarehouseQueryFilter::apply($query, 'warehouse_id');

        return (int) $query->count();
    }
}
