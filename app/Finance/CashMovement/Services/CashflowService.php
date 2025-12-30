<?php

namespace App\Finance\CashMovement\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use Carbon\Carbon;

class CashflowService
{
    /**
     * Obtiene el reporte completo de caja para una fecha específica.
     */
    public function getDailyReport(string $date)
    {
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        // 1. OBTENER VENTAS (SALES)
        $sales = Sale::whereBetween('creation_time', [$startOfDay, $endOfDay])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details']) // IMPORTANTE: Cargamos 'details' para ver qué se vendió
            ->orderBy('creation_time', 'desc')
            ->get()
            ->map(function ($sale) {

                // --- FORMATEO DE DESCRIPCIÓN ---
                // Recorremos los productos vendidos para armar el texto
                $itemsDescription = $sale->details->map(function ($detail) {
                    // Formato: Nombre | talla: Talla | color: Color
                    return "{$detail->product_name_snapshot} | {$detail->size_name_snapshot} | {$detail->color_name_snapshot}";
                })->implode(' + '); // Si hay más de un producto, los unimos con un "+"

                return [
                    'id' => $sale->id,
                    'type' => 'SALE',
                    'time' => $sale->creation_time->format('H:i A'),

                    // FORMATO FINAL: Venta Ropa #Code | Detalles
                    'description' => "Venta Ropa #{$sale->code} | {$itemsDescription}",

                    'method' => $sale->payment_method,
                    'amount' => (float) $sale->total_amount,
                ];
            });

        // 2. OBTENER MOVIMIENTOS MANUALES (INGRESOS/GASTOS)
        $movements = CashMovement::whereBetween('creation_time', [$startOfDay, $endOfDay])
            ->where('is_deleted', false)
            ->orderBy('creation_time', 'desc')
            ->get()
            ->map(function ($mov) {
                return [
                    'id' => $mov->id,
                    'type' => $mov->type,
                    'time' => $mov->creation_time->format('H:i A'),
                    'description' => $mov->description,
                    'method' => $mov->payment_method,
                    'amount' => (float) $mov->amount
                ];
            });

        // 3. AGRUPAR Y CALCULAR TOTALES
        $incomes = $movements->where('type', 'INCOME')->values();
        $expenses = $movements->where('type', 'EXPENSE')->values();

        $totalSales = $sales->sum('amount');
        $totalIncomes = $incomes->sum('amount');
        $totalExpenses = $expenses->sum('amount');

        $openingBalance = 100.00;
        $finalBalance = $openingBalance + $totalSales + $totalIncomes - $totalExpenses;

        return [
            'date' => Carbon::parse($date)->format('d/m/Y'),
            'summary' => [
                'opening_balance' => $openingBalance,
                'total_sales' => $totalSales,
                'total_incomes' => $totalIncomes,
                'total_expenses' => $totalExpenses,
                'final_balance' => $finalBalance
            ],
            'lists' => [
                'sales' => $sales,
                'incomes' => $incomes,
                'expenses' => $expenses
            ]
        ];
    }

    /**
     * Registra un movimiento manual (Gasto o Ingreso)
     */
    public function registerMovement(array $data)
    {
        return CashMovement::create([
            'type' => $data['type'], // INCOME o EXPENSE
            'amount' => $data['amount'],
            'description' => $data['description'],
            'payment_method' => $data['payment_method'] ?? 'CASH',
            'creator_user_id' => auth()->id() ?? 1, // Usuario actual o default
            'creation_time' => now()
        ]);
    }
}
