<?php

namespace App\Report\Services;

use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\Sale\Models\Sale;
use App\Inventory\Product\Models\Product;
use App\Shared\Foundation\Support\WarehouseQueryFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Categorías que sí restan como gasto en reportes de flujo/P&L.
     * INVENTORY_PURCHASE queda fuera: es intercambio caja → inventario.
     */
    private function operatingExpenseCategoriesForSql(): string
    {
        return "'".CashMovement::CATEGORY_ADMINISTRATIVE."','".CashMovement::CATEGORY_STORE."'";
    }

    /**
     * Métodos digitales/bancarios. Misma agrupación que el reporte diario de caja
     * (CashflowService::getDailyReport) cuando están activos YAPE + PLIN + CARD.
     */
    private function digitalPaymentMethodsForSql(): string
    {
        return "'YAPE','PLIN','CARD','TRANSFER'";
    }

    /**
     * Ventas mensuales repartidas por canal usando sale_payments (soporta MIXTO).
     * Misma lógica que /api/cash-flow/daily: cada fila de pago va a su método.
     * Ventas legacy sin sale_payments usan payment_method de la cabecera.
     */
    private function getMonthlySalesAggregatedByPaymentChannel()
    {
        $bancosMethods = $this->digitalPaymentMethodsForSql();

        $fromPayments = DB::table('sales as s')
            ->join('sale_payments as sp', 's.id', '=', 'sp.sale_id')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->selectRaw("
                TO_CHAR(s.creation_time, 'YYYY-MM') as sort_month,
                TO_CHAR(s.creation_time, 'MM-YYYY') as month_year,
                SUM(sp.amount) as total_sales_raw,
                SUM(CASE WHEN sp.method = 'CASH' THEN sp.amount ELSE 0 END) as cash_amount,
                SUM(CASE WHEN sp.method IN ({$bancosMethods}) THEN sp.amount ELSE 0 END) as bancos_amount
            ")
            ->groupByRaw("TO_CHAR(s.creation_time, 'YYYY-MM'), TO_CHAR(s.creation_time, 'MM-YYYY')")
            ->get()
            ->keyBy('sort_month');

        $legacyWithoutPayments = DB::table('sales as s')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('sale_payments as sp')
                    ->whereColumn('sp.sale_id', 's.id');
            })
            ->selectRaw("
                TO_CHAR(s.creation_time, 'YYYY-MM') as sort_month,
                TO_CHAR(s.creation_time, 'MM-YYYY') as month_year,
                SUM(s.total_amount) as total_sales_raw,
                SUM(CASE WHEN s.payment_method = 'CASH' THEN s.total_amount ELSE 0 END) as cash_amount,
                SUM(CASE WHEN s.payment_method IN ({$bancosMethods}) THEN s.total_amount ELSE 0 END) as bancos_amount
            ")
            ->groupByRaw("TO_CHAR(s.creation_time, 'YYYY-MM'), TO_CHAR(s.creation_time, 'MM-YYYY')")
            ->get()
            ->keyBy('sort_month');

        return $this->mergeMonthlyPaymentChannelAggregates($fromPayments, $legacyWithoutPayments);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, object>  $primary
     * @param  \Illuminate\Support\Collection<string, object>  $secondary
     * @return \Illuminate\Support\Collection<string, object>
     */
    private function mergeMonthlyPaymentChannelAggregates($primary, $secondary)
    {
        $allMonths = $primary->keys()->merge($secondary->keys())->unique();

        return $allMonths->mapWithKeys(function (string $month) use ($primary, $secondary) {
            $a = $primary->get($month);
            $b = $secondary->get($month);

            return [$month => (object) [
                'sort_month' => $month,
                'month_year' => $a->month_year ?? $b->month_year,
                'total_sales_raw' => (float) ($a->total_sales_raw ?? 0) + (float) ($b->total_sales_raw ?? 0),
                'cash_amount' => (float) ($a->cash_amount ?? 0) + (float) ($b->cash_amount ?? 0),
                'bancos_amount' => (float) ($a->bancos_amount ?? 0) + (float) ($b->bancos_amount ?? 0),
            ]];
        });
    }

    /**
     * CENTRALIZADOR DE CÁLCULO NETO
     * Calcula: (Ventas Completadas + Ingresos Manuales) - Gastos Operativos Manuales.
     */
    private function calculateNetBalance($start, $end)
    {
        $sales = Sale::whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->sum('total_amount');

        $operatingCategories = $this->operatingExpenseCategoriesForSql();

        $movements = CashMovement::whereBetween('date', [$start, $end])
            ->where('is_deleted', false)
            ->selectRaw("
                SUM(CASE WHEN type = 'INCOME' THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = 'EXPENSE' AND category IN ({$operatingCategories}) THEN amount ELSE 0 END) as expense
            ")->first();

        return (float) ($sales + ($movements->income ?? 0) - ($movements->expense ?? 0));
    }

    /**
     * Totales de la cabecera (KPIs).
     */
    public function getSalesTotals(?string $referenceDate = null)
    {
        $now = Carbon::now();
        $selectedDate = ($referenceDate && trim($referenceDate) !== '')
            ? Carbon::parse($referenceDate)
            : $now;

        // Verificamos si el usuario está viendo el mes actual
        $isCurrentMonth = $selectedDate->isCurrentMonth() && $selectedDate->isCurrentYear();

        return [
            // Solo mostramos diario/semanal si es el mes actual (Marzo 2026)
            'daily' => $isCurrentMonth
                ? $this->calculateNetBalance($now->copy()->startOfDay(), $now->copy()->endOfDay())
                : 0,

            'weekly' => $isCurrentMonth
                ? $this->calculateNetBalance($now->copy()->startOfWeek(), $now->copy()->endOfWeek())
                : 0,

            // Mensual: Siempre coincide con el total de la tabla histórica para ese mes
            'monthly' => $this->calculateNetBalance(
                $selectedDate->copy()->startOfMonth()->startOfDay(),
                $selectedDate->copy()->endOfMonth()->endOfDay()
            ),
        ];
    }

    /**
     * Reporte Financiero (Estado de Resultados).
     */
    public function getFinancialReport(?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfMonth();

        // 1. INGRESOS TOTALES (Ventas + Ingresos de caja)
        $onlySales = Sale::whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')->where('is_deleted', false)
            ->sum('total_amount');

        $otherIncomes = CashMovement::whereBetween('date', [$start, $end])
            ->where('type', 'INCOME')->where('is_deleted', false)
            ->sum('amount');

        $totalRevenue = (float) ($onlySales + $otherIncomes);

        // 2. COSTO DE MERCADERÍA
        $costOfGoodsQuery = DB::table('sales as s')
            ->join('sale_details as sd', 's.id', '=', 'sd.sale_id')
            ->leftJoin('product_size as ps', function ($join) {
                $join->on('sd.product_id', '=', 'ps.product_id')->on('sd.size_id', '=', 'ps.size_id');
            })
            ->whereBetween('s.creation_time', [$start, $end])
            ->where('s.status', 'COMPLETED')->where('s.is_deleted', false);

        WarehouseQueryFilter::apply($costOfGoodsQuery, 's.warehouse_id');

        $costOfGoods = $costOfGoodsQuery
            ->sum(DB::raw('sd.quantity * COALESCE(ps.purchase_price, 0)'));

        // 3. GASTOS OPERATIVOS
        // Se excluyen las compras de mercadería (INVENTORY_PURCHASE): son intercambios de activos
        // (caja → inventario), no gastos deducibles. Su impacto ya aparece en el Costo de Ventas.
        $operatingExpenses = CashMovement::whereBetween('date', [$start, $end])
            ->operatingExpenses()
            ->where('is_deleted', false)
            ->sum('amount');

        $grossProfit = $totalRevenue - (float) $costOfGoods;

        return [
            'period' => $start->format('d/m/Y').' - '.$end->format('d/m/Y'),
            'sales_revenue' => $totalRevenue,
            'cost_of_goods' => (float) $costOfGoods,
            'gross_profit' => $grossProfit,
            'operating_expenses' => (float) $operatingExpenses,
            'net_utility' => $grossProfit - (float) $operatingExpenses,
            'chart_data' => $this->getDailyChartData($start, $end),
        ];
    }

    public function getAllTimeMonthlyReport()
    {
        // VENTAS — reparto por sale_payments (MIXTO incluido), igual que cash-flow/daily.
        $sales = $this->getMonthlySalesAggregatedByPaymentChannel();

        // MOVIMIENTOS — solo gastos operativos restan; INVENTORY_PURCHASE no afecta este reporte.
        $operatingCategories = $this->operatingExpenseCategoriesForSql();
        $bancosMethods = $this->digitalPaymentMethodsForSql();

        $movements = CashMovement::selectRaw("
            TO_CHAR(date, 'YYYY-MM') as sort_month,
            SUM(CASE WHEN payment_method = 'CASH' AND type = 'INCOME' THEN amount
                     WHEN payment_method = 'CASH' AND type = 'EXPENSE'
                          AND category IN ({$operatingCategories}) THEN -amount ELSE 0 END) as net_cash,
            SUM(CASE WHEN payment_method IN ({$bancosMethods}) AND type = 'INCOME' THEN amount
                     WHEN payment_method IN ({$bancosMethods}) AND type = 'EXPENSE'
                          AND category IN ({$operatingCategories}) THEN -amount ELSE 0 END) as net_bancos
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

            $efectivo = ($saleData ? (float) $saleData->cash_amount : 0) + ($movData ? (float) $movData->net_cash : 0);
            $bancos = ($saleData ? (float) $saleData->bancos_amount : 0) + ($movData ? (float) $movData->net_bancos : 0);
            $totalMensual = $efectivo + $bancos;

            $report[] = [
                'fecha' => $fecha,
                'efectivo' => $efectivo,
                'bancos' => $bancos,
                'total_mensual' => $totalMensual,
            ];
        }

        return array_values($report);
    }

    private function getDailyChartData($start, $end)
    {
        $sales = Sale::selectRaw("TO_CHAR(creation_time, 'YYYY-MM-DD') as date, SUM(total_amount) as total")
            ->whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(creation_time, 'YYYY-MM-DD')")
            ->pluck('total', 'date');

        $expenses = CashMovement::selectRaw("TO_CHAR(date, 'YYYY-MM-DD') as date, SUM(amount) as total")
            ->whereBetween('date', [$start, $end])
            ->operatingExpenses()
            ->where('is_deleted', false)
            ->groupByRaw("TO_CHAR(date, 'YYYY-MM-DD')")
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

        return ['labels' => $dates, 'sales' => $dataSales, 'expenses' => $dataExpenses];
    }

    public function getTopProducts(int $limit = 20, ?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : null;

        $topProductsQuery = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('sd.product_id, MAX(sd.product_name_snapshot) as name, SUM(sd.quantity) as total_sold')
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false);

        WarehouseQueryFilter::apply($topProductsQuery, 's.warehouse_id');

        if ($start && $end) {
            $topProductsQuery->whereBetween('s.creation_time', [$start, $end]);
        }

        $topProducts = $topProductsQuery
            ->groupBy('sd.product_id')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();

        if ($topProducts->isEmpty()) {
            return [];
        }

        $productIds = $topProducts->pluck('product_id')->toArray();

        $variantsQuery = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->selectRaw('sd.product_id, sd.color_name_snapshot as color, sd.size_name_snapshot as size, SUM(sd.quantity) as variant_sold')
            ->whereIn('sd.product_id', $productIds)
            ->where('s.status', 'COMPLETED')
            ->where('s.is_deleted', false);

        WarehouseQueryFilter::apply($variantsQuery, 's.warehouse_id');

        if ($start && $end) {
            $variantsQuery->whereBetween('s.creation_time', [$start, $end]);
        }

        $variants = $variantsQuery
            ->groupBy('sd.product_id', 'sd.color_name_snapshot', 'sd.size_name_snapshot')
            ->orderByDesc('variant_sold')
            ->get();

        return $topProducts->map(function ($product) use ($variants) {
            $myVariants = $variants->where('product_id', $product->product_id)->values();
            $topVariantsText = $myVariants->map(fn ($v) => "{$v->variant_sold}-{$v->color}(".str_ireplace(['ESTÁNDAR', 'ESTANDAR'], 'STD', $v->size).')')->implode(' | ');

            return ['name' => $product->name, 'total_sold' => (int) $product->total_sold, 'color' => "Top: {$topVariantsText}"];
        });
    }

    public function getLeastSoldProducts(int $limit = 20, ?string $startDate = null, ?string $endDate = null)
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : null;

        $query = DB::table('products as p')
            ->leftJoin('sale_details as sd', 'p.id', '=', 'sd.product_id')
            ->leftJoin('sales as s', 'sd.sale_id', '=', 's.id');

        WarehouseQueryFilter::apply($query, 'p.warehouse_id');

        if ($start && $end) {
            $query->whereBetween('s.creation_time', [$start, $end]);
        }

        return $query
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('s.status', 'COMPLETED')->where('s.is_deleted', false);
                })->orWhereNull('sd.id');
            })
            ->selectRaw('p.name, p.creation_time as reg_date, COALESCE(SUM(sd.quantity), 0) as total_sold')
            ->groupBy('p.id', 'p.name', 'p.creation_time')
            ->orderBy('total_sold', 'asc')
            ->orderBy('p.creation_time', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->name,
                'registration_date' => $item->reg_date ? Carbon::parse($item->reg_date)->format('d/m/Y') : 'Sin fecha',
                'total_sold' => (int) $item->total_sold,
            ]);
    }

    /**
     * Inventario por producto: tallas (precios y stock) y colores con stock por talla.
     *
     * @return array<int, array{id: int, name: string, sizes: array<int, mixed>}>
     */
    public function getProductsInventoryReport(): array
    {
        $products = Product::query()
            ->where('is_deleted', false)
            ->orderBy('name')
            ->with([
                'productSizes' => function ($q) {
                    $q->with([
                        'size',
                        'colors' => function ($cq) {
                            $cq->where('colors.is_deleted', false)
                                ->orderBy('colors.description');
                        },
                    ]);
                },
            ])
            ->get();

        $stockByBalanceKey = $this->loadStockQuantityMapForProducts($products);

        return $products->map(function (Product $product) use ($stockByBalanceKey) {
            $sizes = $product->productSizes
                ->sortBy(fn ($ps) => $ps->size?->description ?? '')
                ->values()
                ->map(function ($ps) use ($stockByBalanceKey, $product) {
                    $warehouseId = (int) $product->warehouse_id;
                    $productSizeId = (int) $ps->id;
                    $colors = $ps->colors->map(fn ($c) => [
                        'color_id' => $c->id,
                        'color' => $c->description,
                        'stock' => $this->resolveStockFromMap(
                            $stockByBalanceKey,
                            $warehouseId,
                            $productSizeId,
                            (int) $c->id,
                        ),
                    ])->values()->all();

                    $stock = $colors !== []
                        ? array_sum(array_map(static fn (array $color): int => (int) $color['stock'], $colors))
                        : $this->resolveStockFromMap(
                            $stockByBalanceKey,
                            $warehouseId,
                            $productSizeId,
                            null,
                        );

                    return [
                        'product_size_id' => $ps->id,
                        'size_id' => $ps->size_id,
                        'size' => $this->formatSizeLabelForReport($ps->size?->description),
                        'barcode' => $ps->barcode !== null && $ps->barcode !== '' ? (string) $ps->barcode : null,
                        'purchase_price' => $ps->purchase_price !== null ? (float) $ps->purchase_price : null,
                        'sale_price' => $ps->sale_price !== null ? (float) $ps->sale_price : null,
                        'min_sale_price' => $ps->min_sale_price !== null ? (float) $ps->min_sale_price : null,
                        'stock' => $stock,
                        'colors' => $colors,
                    ];
                })->all();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sizes' => $sizes,
            ];
        })->values()->all();
    }

    /**
     * Carga en batch cantidades desde inventory_balances (misma fuente que getAvailableQuantity).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<string, int> clave warehouse:product_size:color → quantity
     */
    private function loadStockQuantityMapForProducts($products): array
    {
        $productSizeIds = [];
        $warehouseIds = [];

        foreach ($products as $product) {
            $warehouseId = (int) $product->warehouse_id;
            if ($warehouseId > 0) {
                $warehouseIds[$warehouseId] = true;
            }

            foreach ($product->productSizes as $productSize) {
                $productSizeIds[(int) $productSize->id] = true;
            }
        }

        if ($productSizeIds === []) {
            return [];
        }

        $query = DB::table('inventory_balances')
            ->whereIn('product_size_id', array_keys($productSizeIds))
            ->select(['warehouse_id', 'product_size_id', 'color_id', 'quantity']);

        if ($warehouseIds !== []) {
            $query->whereIn('warehouse_id', array_keys($warehouseIds));
        }

        WarehouseQueryFilter::apply($query, 'inventory_balances.warehouse_id');

        $map = [];
        foreach ($query->get() as $row) {
            $colorId = $row->color_id !== null ? (int) $row->color_id : null;
            $key = $this->stockBalanceKey(
                (int) $row->warehouse_id,
                (int) $row->product_size_id,
                $colorId,
            );
            $map[$key] = (int) $row->quantity;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $stockByBalanceKey
     */
    private function resolveStockFromMap(
        array $stockByBalanceKey,
        int $warehouseId,
        int $productSizeId,
        ?int $colorId,
    ): int {
        return $stockByBalanceKey[$this->stockBalanceKey($warehouseId, $productSizeId, $colorId)] ?? 0;
    }

    private function stockBalanceKey(int $warehouseId, int $productSizeId, ?int $colorId): string
    {
        return $warehouseId.':'.$productSizeId.':'.($colorId ?? 'null');
    }

    /**
     * Etiqueta de talla para reportes: ESTÁNDAR / ESTANDAR → STD.
     */
    private function formatSizeLabelForReport(?string $description): string
    {
        if ($description === null || trim($description) === '') {
            return '—';
        }

        return str_ireplace(['ESTÁNDAR', 'ESTANDAR'], 'STD', $description);
    }
}
