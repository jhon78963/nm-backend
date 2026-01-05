<?php

namespace App\Report\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportService
{
    /**
     * Obtiene los totales de ventas (Diario, Semanal, Mensual)
     */
    public function getSalesTotals()
    {
        $now = Carbon::now();

        // Diario (Hoy)
        $daily = Sale::whereDate('creation_time', $now->toDateString())
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // Semanal (Lunes a Domingo actual)
        $weekly = Sale::whereBetween('creation_time', [$now->startOfWeek(), $now->endOfWeek()])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // Mensual (Mes actual)
        $monthly = Sale::whereMonth('creation_time', $now->month)
            ->whereYear('creation_time', $now->year)
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly
        ];
    }

    /**
     * Top Productos Más Vendidos (Ranking)
     */
    public function getTopProducts(int $limit = 20)
    {
        return DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('
                sd.product_name_snapshot as name,
                sd.size_name_snapshot as size,
                sd.color_name_snapshot as color,
                SUM(sd.quantity) as total_sold,
                SUM(sd.subtotal) as total_revenue
            ')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->groupBy('sd.product_name_snapshot', 'sd.size_name_snapshot', 'sd.color_name_snapshot')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();
    }

    /**
     * Reporte Financiero Completo (Ingresos, Costos, Utilidad Neta)
     * Rango: Mes Actual por defecto
     */
    public function getFinancialReport(?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate) : Carbon::now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate) : Carbon::now()->endOfMonth();

        // 1. VENTAS BRUTAS (Ingreso por Ventas)
        $salesRevenue = Sale::whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 2. COSTO DE MERCADERÍA VENDIDA (CMV)
        // Calculamos cuánto nos costaron los productos que vendimos
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

        // 3. GANANCIA BRUTA (Ventas - Costo Producto)
        $grossProfit = $salesRevenue - $costOfGoods;

        // 4. GASTOS OPERATIVOS (Luz, Agua, Pasajes...)
        $operatingExpenses = CashMovement::whereBetween('creation_time', [$start, $end])
            ->where('type', 'EXPENSE')
            ->where('is_deleted', false)
            ->sum('amount');

        // 5. UTILIDAD NETA (Ganancia Bruta - Gastos)
        $netUtility = $grossProfit - $operatingExpenses;

        return [
            'period' => $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y'),
            'sales_revenue' => $salesRevenue,
            'cost_of_goods' => $costOfGoods,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'net_utility' => $netUtility,
            // Datos para gráfica diaria (Ventas vs Gastos)
            'chart_data' => $this->getDailyChartData($start, $end)
        ];
    }

    /**
     * Datos para el Gráfico (Día a día)
     */
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

        // Llenar huecos de fechas
        $dates = [];
        $dataSales = [];
        $dataExpenses = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');
            $dates[] = $date->format('d/m');
            $dataSales[] = $sales[$dateStr] ?? 0;
            $dataExpenses[] = $expenses[$dateStr] ?? 0;
        }

        return [
            'labels' => $dates,
            'sales' => $dataSales,
            'expenses' => $dataExpenses
        ];
    }
}
