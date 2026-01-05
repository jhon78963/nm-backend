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
        // Validación robusta: Si es null o string vacío, usa HOY.
        $date = ($referenceDate && trim($referenceDate) !== '')
            ? Carbon::parse($referenceDate)
            : Carbon::now();

        // 1. Diario (Ventas del día seleccionado)
        $daily = Sale::whereDate('creation_time', $date->toDateString())
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 2. Semanal (Ventas de la semana de la fecha seleccionada)
        // Usamos copy() para no modificar la instancia original de $date
        $weekly = Sale::whereBetween('creation_time', [
                $date->copy()->startOfWeek(),
                $date->copy()->endOfWeek()
            ])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 3. Mensual (Ventas del mes de la fecha seleccionada)
        $monthly = Sale::whereMonth('creation_time', $date->month)
            ->whereYear('creation_time', $date->year)
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        return [
            'daily' => (float)$daily,
            'weekly' => (float)$weekly,
            'monthly' => (float)$monthly
        ];
    }

    /**
     * Top Productos Más Vendidos (Filtrado por fecha)
     */
    public function getTopProducts(int $limit = 20, ?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate) : Carbon::now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate) : Carbon::now()->endOfMonth();

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
            // IMPORTANTE: Filtramos por el rango de fechas seleccionado
            ->whereBetween('s.creation_time', [$start, $end])
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
            ->leftJoin('product_size as ps', function($join) {
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
            'sales_revenue' => (float)$salesRevenue,
            'cost_of_goods' => (float)$costOfGoods,
            'gross_profit' => (float)$grossProfit,
            'operating_expenses' => (float)$operatingExpenses,
            'net_utility' => (float)$netUtility,
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

            // Casting explícito a float para corregir el error del gráfico
            $dataSales[] = isset($sales[$dateStr]) ? (float)$sales[$dateStr] : 0;
            $dataExpenses[] = isset($expenses[$dateStr]) ? (float)$expenses[$dateStr] : 0;
        }

        return [
            'labels' => $dates,
            'sales' => $dataSales,
            'expenses' => $dataExpenses
        ];
    }
}
