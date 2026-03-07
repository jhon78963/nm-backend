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
        // Las ventas las seguimos filtrando por creation_time a menos que también hayas
        // agregado un campo 'date' específico a la tabla sales. Asumiremos creation_time
        // por ahora basándonos en tu código anterior.
        $sales = Sale::whereBetween('creation_time', [$startOfDay, $endOfDay])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details'])
            ->orderBy('creation_time', 'desc')
            ->get()
            ->map(function ($sale) {
                $itemsDescription = $sale->details->map(function ($detail) {
                    return "{$detail->product_name_snapshot} | {$detail->size_name_snapshot} | {$detail->color_name_snapshot}";
                })->implode(' + ');

                return [
                    'id' => $sale->id,
                    'type' => 'SALE',
                    'time' => $sale->creation_time->format('H:i A'),
                    'description' => "{$sale->code} | {$itemsDescription}",
                    'method' => $sale->payment_method,
                    'amount' => (float) $sale->total_amount,
                ];
            });

        // 2. OBTENER MOVIMIENTOS MANUALES (INGRESOS/GASTOS)
        // AQUI CAMBIAMOS PARA FILTRAR Y ORDENAR POR 'date'
        $movements = CashMovement::whereBetween('date', [$startOfDay, $endOfDay])
            ->where('is_deleted', false)
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($mov) {
                return [
                    'id' => $mov->id,
                    'type' => $mov->type,
                    'time' => $mov->date->format('H:i A'), // Usamos date en lugar de creation_time
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
            'date' => $data['date'],
        ]);
    }
}
