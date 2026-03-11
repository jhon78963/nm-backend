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
        $topProducts = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('
                sd.product_id,
                MAX(sd.product_name_snapshot) as name,
                CAST(SUM(sd.quantity) AS INTEGER) as total_sold
            ')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->groupBy('sd.product_id')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();

        if ($topProducts->isEmpty()) {
            return [];
        }

        $productIds = $topProducts->pluck('product_id')->toArray();

        // 2. Obtenemos CÓMO se dividieron esas ventas (solo para los top productos)
        $variants = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('
                sd.product_id,
                sd.color_name_snapshot as color,
                sd.size_name_snapshot as size,
                CAST(SUM(sd.quantity) AS INTEGER) as variant_sold
            ')
            ->whereIn('sd.product_id', $productIds)
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->groupBy('sd.product_id', 'sd.color_name_snapshot', 'sd.size_name_snapshot')
            ->orderByDesc('variant_sold')
            ->get();

        // 3. Unimos la data armando el texto para tu frontend
        return $topProducts->map(function ($product) use ($variants) {
            // Filtramos las variantes de este producto específico
            $myVariants = $variants->where('product_id', $product->product_id)->values();

            // Tomamos las 4 variantes más vendidas
            $topVariantsText = $myVariants->map(function ($v) {
                // Reemplazamos ESTÁNDAR o ESTANDAR por STD
                $sizeLabel = str_ireplace(['ESTÁNDAR', 'ESTANDAR'], 'STD', $v->size);

                // Formato final con la cantidad: "3 - Celeste (30)"
                return "{$v->variant_sold} - {$v->color} ({$sizeLabel})";
            })->implode(' | ');

            return [
                'name' => $product->name,
                'total_sold' => $product->total_sold,
                'color' => $myVariants->count() > 100
                    ? "Top: {$topVariantsText}..."
                    : "Top: {$topVariantsText}"
            ];
        });
    }

    /**
     * Ranking de Productos Menos Vendidos
     * Incluye productos con 0 ventas y su fecha de registro.
     */
    public function getLeastSoldProducts(int $limit = 20)
    {
        return DB::table('products as p')
            // Unimos con detalles de venta para contar cantidades
            ->leftJoin('sale_details as sd', 'p.id', '=', 'sd.product_id')
            // Unimos con ventas para filtrar solo las completadas y no eliminadas
            ->leftJoin('sales as s', function ($join) {
                $join->on('sd.sale_id', '=', 's.id')
                    ->where('s.status', '=', 'COMPLETED')
                    ->where('s.is_deleted', '=', false);
            })
            ->selectRaw('
                p.name,
                p.creation_time as registration_date,
                CAST(COALESCE(SUM(sd.quantity), 0) AS INTEGER) as total_sold
            ')
            // Opcional: filtrar si el producto en sí no está marcado como eliminado
            // ->where('p.is_deleted', false)
            ->groupBy('p.id', 'p.name', 'p.creation_time')
            // Ordenamos de menor a mayor venta
            ->orderBy('total_sold', 'asc')
            // En caso de empate en ventas (como muchos con 0), mostrar los más antiguos primero
            ->orderBy('p.creation_time', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->name,
                    'registration_date' => $item->registration_date
                        ? Carbon::parse($item->registration_date)->format('d/m/Y')
                        : 'Sin fecha',
                    'total_sold' => $item->total_sold
                ];
            });
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
