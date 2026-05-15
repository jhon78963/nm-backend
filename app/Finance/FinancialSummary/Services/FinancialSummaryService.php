<?php

namespace App\Finance\FinancialSummary\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialSummaryService
{
    public function getSummary()
    {
        // Rango de tiempo: Mes actual por defecto (puedes parametrizarlo si quieres)
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        // 1. CALCULAR CAJA TOTAL (Saldo acumulado histórico)
        // Fórmula: (Total Ventas + Total Ingresos) - (Total Gastos) + Caja Chica Base
        $totalSalesAllTime = Sale::where('status', 'COMPLETED')->where('is_deleted', false)->sum('total_amount');
        $totalIncomesAllTime = CashMovement::where('type', 'INCOME')->where('is_deleted', false)->sum('amount');
        $totalExpensesAllTime = CashMovement::where('type', 'EXPENSE')->where('is_deleted', false)->sum('amount');

        $baseCash = 100.00; // Caja chica fija (si es constante)
        $currentCash = $baseCash + $totalSalesAllTime + $totalIncomesAllTime - $totalExpensesAllTime;

        // Desglose Efectivo vs Digital (Aprox. basado en ventas, ya que gastos suelen ser efectivo)
        // Esto es una estimación rápida. Para precisión total requeriría sumar por método de pago.
        $cashSales = Sale::where('payment_method', 'CASH')->where('status', 'COMPLETED')->sum('total_amount');
        $digitalSales = $totalSalesAllTime - $cashSales;


        // 2. INGRESOS VENTAS (Este Mes)
        $monthlySales = Sale::whereBetween('creation_time', [$startOfMonth, $endOfMonth])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // Comparativa mes anterior para el porcentaje (ej: +12%)
        $lastMonthSales = Sale::whereBetween('creation_time', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->where('status', 'COMPLETED')
            ->sum('total_amount');

        $growthPercentage = $lastMonthSales > 0
            ? round((($monthlySales - $lastMonthSales) / $lastMonthSales) * 100, 1)
            : 100;


        // 3. GASTOS OPERATIVOS (Este Mes)
        // Filtramos solo gastos que NO sean "Compra de Mercadería" si los diferencias por descripción o categoría
        $monthlyExpenses = CashMovement::where('type', 'EXPENSE')
            ->whereBetween('creation_time', [$startOfMonth, $endOfMonth])
            ->where('is_deleted', false)
            ->sum('amount');


        $monthlyInvestment = (float) DB::table('inventory_movements as im')
            ->join('product_size as ps', 'ps.id', '=', 'im.product_size_id')
            ->whereBetween('im.occurred_at', [$startOfMonth, $endOfMonth])
            ->where('im.direction', InventoryMovementDirection::In->value)
            ->whereIn('im.movement_type', [
                InventoryMovementType::InitialInventory->value,
                InventoryMovementType::Purchase->value,
                InventoryMovementType::Reconciliation->value,
            ])
            ->sum(DB::raw('im.quantity * COALESCE(ps.purchase_price, 0)'));


        // 5. MOVIMIENTOS RECIENTES (Tabla)
        // Mezclamos Ventas y Movimientos de Caja, ordenamos por fecha y tomamos los últimos 5
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
            DB::raw("CASE WHEN type = 'INCOME' THEN 'Ingreso' ELSE 'Gasto' END as category"),
            'creation_time as date',
            'payment_method as method',
            'amount',
            DB::raw("CASE WHEN type = 'INCOME' THEN 'income' ELSE 'expense' END as type")
        )
            ->where('is_deleted', false)
            ->orderBy('creation_time', 'desc')
            ->limit(5)
            ->get();

        // Unir y ordenar
        $recentTransactions = $recentSales->concat($recentMovements)
            ->sortByDesc('date')
            ->take(10)
            ->values()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'concept' => $item->category === 'Venta' ? "Venta POS #{$item->concept}" : $item->concept,
                    'category' => $item->category,
                    'date' => Carbon::parse($item->date)->format('d/m/Y h:i A'), // Formato amigable "Hoy, 12:30 PM"
                    'method' => $item->method,
                    'amount' => (float) $item->amount,
                    'type' => $item->type // 'income' o 'expense' para saber si poner + o -
                ];
            });

        return [
            'cards' => [
                'cash_total' => [
                    'amount' => $currentCash,
                    'cash' => $cashSales, // Aprox
                    'digital' => $digitalSales // Aprox
                ],
                'sales_income' => [
                    'amount' => $monthlySales,
                    'growth' => $growthPercentage
                ],
                'expenses' => [
                    'amount' => $monthlyExpenses,
                    'description' => 'Luz, Pasajes, Comida'
                ],
                'stock_investment' => [
                    'amount' => $monthlyInvestment, // Conectar con lógica real
                    'description' => 'Compras recuperables'
                ]
            ],
            'recent_transactions' => $recentTransactions
        ];
    }
}
