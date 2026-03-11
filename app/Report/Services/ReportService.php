<?php

namespace App\Report\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportService
{
    /**
     * Centraliza el cálculo: Ventas + Ingresos - Gastos.
     * Garantiza que todos los componentes del reporte muestren el mismo valor neto.
     */
    private function getNetBalance($start, $end)
    {
        // Suma de ventas completadas
        $sales = Sale::whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // Suma neta de movimientos (Ingresos - Gastos)
        $movements = CashMovement::whereBetween('date', [$start, $end])
            ->where('is_deleted', false)
            ->selectRaw("
                SUM(CASE WHEN type = 'INCOME' THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = 'EXPENSE' THEN amount ELSE 0 END) as expense
            ")->first();

        return (float) ($sales + ($movements->income ?? 0) - ($movements->expense ?? 0));
    }

    /**
     * Obtiene los totales de la cabecera.
     * El diario y semanal se basan en la fecha actual (Hoy).
     * El mensual se basa en el mes de la fecha seleccionada en el filtro.
     */
    public function getSalesTotals(?string $referenceDate = null)
    {
        $now = Carbon::now();
        $selectedDate = ($referenceDate && trim($referenceDate) !== '')
            ? Carbon::parse($referenceDate)
            : $now;

        return [
            // Hoy en tiempo real
            'daily' => $this->getNetBalance($now->copy()->startOfDay(), $now->copy()->endOfDay()),

            // Lunes a Domingo de la semana actual
            'weekly' => $this->getNetBalance($now->copy()->startOfWeek(), $now->copy()->endOfWeek()),

            // Mes completo del filtro seleccionado
            'monthly' => $this->getNetBalance(
                $selectedDate->copy()->startOfMonth()->startOfDay(),
                $selectedDate->copy()->endOfMonth()->endOfDay()
            )
        ];
    }

    /**
     * Reporte Financiero (Estado de Resultados).
     * Ajustado para que las Ventas Brutas incluyan los ingresos manuales de caja.
     */
    public function getFinancialReport(?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfMonth();

        // Ventas de productos
        $onlySales = Sale::whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        // Entradas manuales de dinero
        $otherIncomes = CashMovement::whereBetween('date', [$start, $end])
            ->where('type', 'INCOME')
            ->where('is_deleted', false)
            ->sum('amount');

        // Total Ingresos (Equivale al Total Mensual de la tabla histórica)
        $totalRevenue = (float)($onlySales + $otherIncomes);

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

        $operatingExpenses = CashMovement::whereBetween('date', [$start, $end])
            ->where('type', 'EXPENSE')
            ->where('is_deleted', false)
            ->sum('amount');

        $grossProfit = $totalRevenue - (float)$costOfGoods;

        return [
            'period' => $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y'),
            'sales_revenue' => $totalRevenue,
            'cost_of_goods' => (float)$costOfGoods,
            'gross_profit' => $grossProfit,
            'operating_expenses' => (float)$operatingExpenses,
            'net_utility' => $grossProfit - (float)$operatingExpenses,
            'chart_data' => $this->getDailyChartData($start, $end)
        ];
    }

    private function getDailyChartData($start, $end)
    {
        // Corrección para PostgreSQL: Usar groupByRaw con la misma expresión del SELECT
        $sales = Sale::selectRaw("TO_CHAR(creation_time, 'YYYY-MM-DD') as date, SUM(total_amount) as total")
            ->whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(creation_time, 'YYYY-MM-DD')")
            ->pluck('total', 'date');

        $expenses = CashMovement::selectRaw("TO_CHAR(date, 'YYYY-MM-DD') as date, SUM(amount) as total")
            ->whereBetween('date', [$start, $end])
            ->where('type', 'EXPENSE')
            ->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(date, 'YYYY-MM-DD')")
            ->pluck('total', 'date');

        $dates = [];
        $dataSales = [];
        $dataExpenses = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');
            $dates[] = $date->format('d/m');
            $dataSales[] = isset($sales[$dateStr]) ? (float)$sales[$dateStr] : 0;
            $dataExpenses[] = isset($expenses[$dateStr]) ? (float)$expenses[$dateStr] : 0;
        }

        return ['labels' => $dates, 'sales' => $dataSales, 'expenses' => $dataExpenses];
    }

    public function getTopProducts(int $limit = 20)
    {
        $topProducts = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('sd.product_id, MAX(sd.product_name_snapshot) as name, CAST(SUM(sd.quantity) AS INTEGER) as total_sold')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->groupBy('sd.product_id')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();

        if ($topProducts->isEmpty()) return [];

        $productIds = $topProducts->pluck('product_id')->toArray();
        $variants = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('sd.product_id, sd.color_name_snapshot as color, sd.size_name_snapshot as size, CAST(SUM(sd.quantity) AS INTEGER) as variant_sold')
            ->whereIn('sd.product_id', $productIds)
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->groupBy('sd.product_id', 'sd.color_name_snapshot', 'sd.size_name_snapshot')
            ->orderByDesc('variant_sold')->get();

        return $topProducts->map(function ($product) use ($variants) {
            $myVariants = $variants->where('product_id', $product->product_id)->values();
            $topVariantsText = $myVariants->map(fn($v) => "{$v->variant_sold}-{$v->color}(".str_ireplace(['ESTÁNDAR','ESTANDAR'],'STD',$v->size).")")->implode(' | ');
            return ['name' => $product->name, 'total_sold' => $product->total_sold, 'color' => "Top: {$topVariantsText}"];
        });
    }

    public function getLeastSoldProducts(int $limit = 20)
    {
        return DB::table('products as p')
            ->leftJoin('sale_details as sd', 'p.id', '=', 'sd.product_id')
            ->leftJoin('sales as s', fn($j) => $j->on('sd.sale_id','=','s.id')->where('s.status','COMPLETED')->where('s.is_deleted',false))
            ->selectRaw('p.name, p.creation_time as reg_date, CAST(COALESCE(SUM(sd.quantity), 0) AS INTEGER) as total_sold')
            ->groupBy('p.id', 'p.name', 'p.creation_time')
            ->orderBy('total_sold', 'asc')->orderBy('p.creation_time', 'asc')
            ->limit($limit)->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'registration_date' => $item->reg_date ? Carbon::parse($item->reg_date)->format('d/m/Y') : 'Sin fecha',
                'total_sold' => $item->total_sold
            ]);
    }

    public function getAllTimeMonthlyReport()
    {
        $sales = Sale::selectRaw("
            TO_CHAR(creation_time, 'YYYY-MM') as sort_month,
            TO_CHAR(creation_time, 'MM-YYYY') as month_year,
            SUM(CASE WHEN payment_method = 'CASH' THEN total_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'YAPE' THEN total_amount ELSE 0 END) as yape_amount,
            SUM(CASE WHEN payment_method IN ('CARD', 'TRANSFER') THEN total_amount ELSE 0 END) as card_transfer_amount
        ")
            ->where('status', 'COMPLETED')->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(creation_time, 'YYYY-MM'), TO_CHAR(creation_time, 'MM-YYYY')")
            ->get()->keyBy('sort_month');

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
            ->get()->keyBy('sort_month');

        $allMonths = $sales->keys()->merge($movements->keys())->unique()->sort();
        $report = [];

        foreach ($allMonths as $month) {
            $saleData = $sales->get($month);
            $movData = $movements->get($month);
            $fecha = $saleData ? $saleData->month_year : Carbon::createFromFormat('Y-m', $month)->format('m-Y');

            $efectivo = ($saleData ? (float)$saleData->cash_amount : 0) + ($movData ? (float)$movData->net_cash : 0);
            $yape = ($saleData ? (float)$saleData->yape_amount : 0) + ($movData ? (float)$movData->net_yape : 0);
            $tarjeta = ($saleData ? (float)$saleData->card_transfer_amount : 0) + ($movData ? (float)$movData->net_card_transfer : 0);

            $report[] = [
                'fecha' => $fecha, 'efectivo' => $efectivo, 'yape' => $yape,
                'tarjeta_transferencia' => $tarjeta, 'total_mensual' => $efectivo + $yape + $tarjeta
            ];
        }
        return array_values($report);
    }
}
