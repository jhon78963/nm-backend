<?php

namespace App\Finance\FinancialSummary\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Shared\Foundation\Support\WarehouseQueryFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialSummaryService
{
    public function getSummary()
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $totalSalesAllTime = Sale::where('status', 'COMPLETED')->where('is_deleted', false)->sum('total_amount');
        $totalIncomesAllTime = CashMovement::query()
            ->where('type', CashMovement::TYPE_INCOME)
            ->where('is_deleted', false)
            ->sum('amount');
        $totalExpensesAllTime = CashMovement::query()
            ->operatingExpenses()
            ->where('is_deleted', false)
            ->sum('amount');

        $baseCash = 100.00;
        $currentCash = $baseCash + $totalSalesAllTime + $totalIncomesAllTime - $totalExpensesAllTime;

        $cashSales = Sale::where('payment_method', 'CASH')->where('status', 'COMPLETED')->sum('total_amount');
        $digitalSales = $totalSalesAllTime - $cashSales;

        $monthlySales = Sale::whereBetween('creation_time', [$startOfMonth, $endOfMonth])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        $lastMonthSales = Sale::whereBetween('creation_time', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->where('status', 'COMPLETED')
            ->sum('total_amount');

        $growthPercentage = $lastMonthSales > 0
            ? round((($monthlySales - $lastMonthSales) / $lastMonthSales) * 100, 1)
            : 100;

        $monthlyExpenses = CashMovement::query()
            ->operatingExpenses()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_deleted', false)
            ->sum('amount');

        $monthlyAdministrativeExpenses = CashMovement::query()
            ->administrativeExpenses()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_deleted', false)
            ->sum('amount');

        $monthlyStoreExpenses = CashMovement::query()
            ->storeMovements()
            ->where('type', CashMovement::TYPE_EXPENSE)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_deleted', false)
            ->sum('amount');

        $monthlyInvestmentQuery = DB::table('inventory_movements as im')
            ->join('product_size as ps', 'ps.id', '=', 'im.product_size_id')
            ->whereBetween('im.occurred_at', [$startOfMonth, $endOfMonth])
            ->where('im.direction', InventoryMovementDirection::In->value)
            ->whereIn('im.movement_type', [
                InventoryMovementType::InitialInventory->value,
                InventoryMovementType::Purchase->value,
                InventoryMovementType::Reconciliation->value,
            ]);

        WarehouseQueryFilter::apply($monthlyInvestmentQuery, 'im.warehouse_id');

        $monthlyInvestment = (float) $monthlyInvestmentQuery
            ->sum(DB::raw('im.quantity * COALESCE(ps.purchase_price, 0)'));

        $recentSales = Sale::select(
            'id',
            'code as concept',
            DB::raw("'Venta' as category"),
            'creation_time as date',
            'payment_method as method',
            'total_amount as amount',
            DB::raw("'income' as type")
        )
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->orderBy('creation_time', 'desc')
            ->limit(5)
            ->get();

        $recentMovements = CashMovement::select(
            'id',
            'description as concept',
            DB::raw("CASE
                WHEN type = 'INCOME' THEN 'Ingreso'
                WHEN category = 'ADMINISTRATIVE' THEN 'Gasto administrativo'
                ELSE 'Gasto tienda'
            END as category"),
            'date',
            'payment_method as method',
            'amount',
            DB::raw("CASE WHEN type = 'INCOME' THEN 'income' ELSE 'expense' END as type")
        )
            ->where('is_deleted', false)
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get();

        $recentTransactions = $recentSales->concat($recentMovements)
            ->sortByDesc('date')
            ->take(10)
            ->values()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'concept' => $item->category === 'Venta' ? "Venta POS #{$item->concept}" : $item->concept,
                    'category' => $item->category,
                    'date' => Carbon::parse($item->date)->format('d/m/Y h:i A'),
                    'method' => $item->method,
                    'amount' => (float) $item->amount,
                    'type' => $item->type,
                ];
            });

        return [
            'cards' => [
                'cash_total' => [
                    'amount' => $currentCash,
                    'cash' => $cashSales,
                    'digital' => $digitalSales,
                ],
                'sales_income' => [
                    'amount' => $monthlySales,
                    'growth' => $growthPercentage,
                ],
                'expenses' => [
                    'amount' => $monthlyExpenses,
                    'description' => sprintf(
                        'Administrativos: S/ %s · Tienda: S/ %s',
                        number_format((float) $monthlyAdministrativeExpenses, 2),
                        number_format((float) $monthlyStoreExpenses, 2),
                    ),
                ],
                'stock_investment' => [
                    'amount' => $monthlyInvestment,
                    'description' => 'Compras recuperables',
                ],
            ],
            'recent_transactions' => $recentTransactions,
        ];
    }
}
