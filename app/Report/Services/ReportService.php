<?php

namespace App\Report\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportService
{
    /**
     * Obtiene los totales de ventas basados en una fecha de referencia.
     */
    public function getSalesTotals(?string $referenceDate = null)
    {
        $date = ($referenceDate && trim($referenceDate) !== '')
            ? Carbon::parse($referenceDate)
            : Carbon::now();

        // 1. Diario (Día específico)
        $daily = Sale::whereDate('creation_time', $date->toDateString())
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 2. Semanal (CORREGIDO: Limitado al mes actual)
        // Calculamos el inicio y fin de la semana natural
        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();

        // TRUCO: Si el inicio de la semana cae en el mes anterior, lo forzamos al día 1 del mes actual.
        // Así el "Semanal" nunca traerá ventas del mes pasado.
        if ($weekStart->month !== $date->month) {
            $weekStart = $date->copy()->startOfMonth();
        }

        // Lo mismo para el final (aunque startOfWeek suele ser el problema al inicio de mes)
        if ($weekEnd->month !== $date->month) {
            $weekEnd = $date->copy()->endOfMonth();
        }

        $weekly = Sale::whereBetween('creation_time', [$weekStart, $weekEnd])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 3. Mensual
        $monthly = Sale::whereMonth('creation_time', $date->month)
            ->whereYear('creation_time', $date->year)
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        return [
            'daily' => (float) $daily,
            'weekly' => (float) $weekly,
            'monthly' => (float) $monthly
        ];
    }

    /**
     * Top Productos Más Vendidos
     */
    public function getTopProducts(int $limit = 20)
    {
        // Eliminamos $startDate y $endDate para que sea global
        return DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('
                sd.product_name_snapshot as name,
                sd.size_name_snapshot as size,
                sd.color_name_snapshot as color,
                CAST(SUM(sd.quantity) AS INTEGER) as total_sold,
                CAST(SUM(sd.subtotal) AS FLOAT) as total_revenue
            ')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            // ->whereBetween(...) <--- ELIMINADO: Ahora es histórico total
            ->groupBy('sd.product_name_snapshot', 'sd.size_name_snapshot', 'sd.color_name_snapshot')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();
    }

    /**
     * Reporte Financiero Completo
     */
    public function getFinancialReport(?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate) : Carbon::now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate) : Carbon::now()->endOfMonth();

        // 1. VENTAS BRUTAS
        $salesRevenue = Sale::whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 2. COSTO DE MERCADERÍA
        $costOfGoods = DB::table('sales as s')
            ->join('sale_details as sd', 's.id', '=', 'sd.sale_id')
            ->leftJoin('product_size as ps', function ($join) {
                $join->on('sd.product_id', '=', 'ps.product_id')
                    ->on('sd.size_id', '=', 'ps.size_id');
            })
            ->whereBetween('s.creation_time', [$start, $end])
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->sum(DB::raw('sd.quantity * COALESCE(ps.purchase_price, 0)'));

        // 3. GANANCIA BRUTA
        $grossProfit = $salesRevenue - $costOfGoods;

        // 4. GASTOS OPERATIVOS
        $operatingExpenses = CashMovement::whereBetween('creation_time', [$start, $end])
            ->where('type', 'EXPENSE')
            ->where('is_deleted', false)
            ->sum('amount');

        // 5. UTILIDAD NETA
        $netUtility = $grossProfit - $operatingExpenses;

        return [
            'period' => $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y'),
            'sales_revenue' => (float) $salesRevenue,
            'cost_of_goods' => (float) $costOfGoods,
            'gross_profit' => (float) $grossProfit,
            'operating_expenses' => (float) $operatingExpenses,
            'net_utility' => (float) $netUtility,
            'chart_data' => $this->getDailyChartData($start, $end)
        ];
    }

    private function getDailyChartData($start, $end)
    {
        $sales = Sale::selectRaw("TO_CHAR(creation_time, 'YYYY-MM-DD') as date, SUM(total_amount) as total")
            ->whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->groupBy('date')
            ->pluck('total', 'date');

        $expenses = CashMovement::selectRaw("TO_CHAR(creation_time, 'YYYY-MM-DD') as date, SUM(amount) as total")
            ->whereBetween('creation_time', [$start, $end])
            ->where('type', 'EXPENSE')
            ->groupBy('date')
            ->pluck('total', 'date');

        $dates = [];
        $dataSales = [];
        $dataExpenses = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');
            $dates[] = $date->format('d/m');
            $dataSales[] = isset($sales[$dateStr]) ? (float) $sales[$dateStr] : 0;
            $dataExpenses[] = isset($expenses[$dateStr]) ? (float) $expenses[$dateStr] : 0;
        }

        return [
            'labels' => $dates,
            'sales' => $dataSales,
            'expenses' => $dataExpenses
        ];
    }
}
