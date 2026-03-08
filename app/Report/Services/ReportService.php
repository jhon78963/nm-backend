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
        try {
            $date = ($referenceDate && trim($referenceDate) !== '')
                ? Carbon::parse($referenceDate)
                : Carbon::now();
        } catch (\Exception $e) {
            $date = Carbon::now();
        }

        // 1. Diario (Ventas del día de referencia)
        $daily = Sale::whereDate('creation_time', $date->toDateString())
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 2. Semanal (Lunes a Domingo de la semana de referencia)
        // Forzamos el límite al mes actual para no arrastrar ventas de otros meses
        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();

        if ($weekStart->month !== $date->month) {
            $weekStart = $date->copy()->startOfMonth();
        }
        if ($weekEnd->month !== $date->month) {
            $weekEnd = $date->copy()->endOfMonth();
        }

        $weekly = Sale::whereBetween('creation_time', [$weekStart->startOfDay(), $weekEnd->endOfDay()])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // 3. Mensual (Mes completo de la fecha de referencia)
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
     * Top Productos Más Vendidos (RANKING GENERAL HISTÓRICO)
     * No filtra por fechas, muestra los "best sellers" de siempre.
     */
    public function getTopProducts(int $limit = 20)
    {
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
        // CORRECCIÓN CLAVE: Usar startOfDay() y endOfDay() para incluir todas las horas
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfMonth();

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
        $operatingExpenses = CashMovement::whereBetween('date', [$start, $end])
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

        $expenses = CashMovement::selectRaw("TO_CHAR(date, 'YYYY-MM-DD') as date, SUM(amount) as total")
            ->whereBetween('date', [$start, $end])
            ->where('type', 'EXPENSE')
            ->groupBy('date')
            ->pluck('total', 'date');

        $dates = [];
        $dataSales = [];
        $dataExpenses = [];

        // Iteramos día por día para llenar huecos con 0
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

    // public function getAllTimeMonthlyReport()
    // {
    //     $report = Sale::selectRaw(expression: "
    //         TO_CHAR(creation_time, 'MM-YYYY') as month_year,
    //         SUM(CASE WHEN payment_method = 'CASH' THEN total_amount ELSE 0 END) as cash_amount,
    //         SUM(CASE WHEN payment_method = 'YAPE' THEN total_amount ELSE 0 END) as yape_amount,
    //         SUM(CASE WHEN payment_method IN ('CARD', 'TRANSFER') THEN total_amount ELSE 0 END) as card_transfer_amount,
    //         SUM(total_amount) as total_month
    //     ")
    //         ->where(column: 'status', operator: '=', value: 'COMPLETED')
    //         ->where(column: 'is_deleted', operator: '=', value: false)
    //         ->groupByRaw(sql: "TO_CHAR(creation_time, 'YYYY-MM'), TO_CHAR(creation_time, 'MM-YYYY')")
    //         ->orderByRaw(sql: "TO_CHAR(creation_time, 'YYYY-MM') ASC")
    //         ->get();

    //     return $report->map(callback: fn($row): array => [
    //         'fecha' => $row->month_year,
    //         'efectivo' => (float) $row->cash_amount,
    //         'yape' => (float) $row->yape_amount,
    //         'tarjeta_transferencia' => (float) $row->card_transfer_amount,
    //         'total_mensual' => (float) $row->total_month
    //     ]);
    // }

    public function getAllTimeMonthlyReport()
    {
        // 1. VENTAS: Agrupadas por mes y método de pago
        $sales = Sale::selectRaw("
            TO_CHAR(creation_time, 'YYYY-MM') as sort_month,
            TO_CHAR(creation_time, 'MM-YYYY') as month_year,
            SUM(CASE WHEN payment_method = 'CASH' THEN total_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'YAPE' THEN total_amount ELSE 0 END) as yape_amount,
            SUM(CASE WHEN payment_method IN ('CARD', 'TRANSFER') THEN total_amount ELSE 0 END) as card_transfer_amount
        ")
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(creation_time, 'YYYY-MM'), TO_CHAR(creation_time, 'MM-YYYY')")
            ->get()
            ->keyBy('sort_month');

        // 2. MOVIMIENTOS DE CAJA: Agrupados por mes y calculando el NETO por método de pago
        // (Suma si es INCOME, Resta si es EXPENSE)
        $movements = CashMovement::selectRaw("
            TO_CHAR(date, 'YYYY-MM') as sort_month,
            SUM(CASE WHEN payment_method = 'CASH' AND type = 'INCOME' THEN amount
                     WHEN payment_method = 'CASH' AND type = 'EXPENSE' THEN -amount ELSE 0 END) as net_cash,
            SUM(CASE WHEN payment_method = 'YAPE' AND type = 'INCOME' THEN amount
                     WHEN payment_method = 'YAPE' AND type = 'EXPENSE' THEN -amount ELSE 0 END) as net_yape,
            SUM(CASE WHEN payment_method IN ('CARD', 'TRANSFER') AND type = 'INCOME' THEN amount
                     WHEN payment_method IN ('CARD', 'TRANSFER') AND type = 'EXPENSE' THEN -amount ELSE 0 END) as net_card_transfer
        ")
            ->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(date, 'YYYY-MM')")
            ->get()
            ->keyBy('sort_month');

        // 3. Unir los meses únicos de ambas consultas
        $allMonths = $sales->keys()->merge($movements->keys())->unique()->sort();

        $report = [];

        foreach ($allMonths as $month) {
            $saleData = $sales->get($month);
            $movData = $movements->get($month);

            $fecha = $saleData ? $saleData->month_year : \Carbon\Carbon::createFromFormat('Y-m', $month)->format('m-Y');

            // Calculamos cada columna sumando las ventas + los movimientos netos (que ya vienen en positivo o negativo)
            $efectivo = ($saleData ? (float) $saleData->cash_amount : 0) + ($movData ? (float) $movData->net_cash : 0);
            $yape = ($saleData ? (float) $saleData->yape_amount : 0) + ($movData ? (float) $movData->net_yape : 0);
            $tarjeta = ($saleData ? (float) $saleData->card_transfer_amount : 0) + ($movData ? (float) $movData->net_card_transfer : 0);

            // El total mensual es simplemente la suma de los 3 métodos de pago ya calculados
            $totalMensual = $efectivo + $yape + $tarjeta;

            $report[] = [
                'fecha' => $fecha,
                'efectivo' => $efectivo,
                'yape' => $yape,
                'tarjeta_transferencia' => $tarjeta,
                'total_mensual' => $totalMensual
            ];
        }

        return array_values($report);
    }
}
