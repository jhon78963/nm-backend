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
     * Métodos digitales alineados con Control de Caja (YAPE/PLIN comparten filtro).
     *
     * @return list<string>
     */
    private function digitalPaymentMethods(): array
    {
        return ['YAPE', 'PLIN', 'CARD', 'TRANSFER'];
    }

    /**
     * Ingresos manuales de tienda (caja): chasqui, otros ingresos STORE/INCOME.
     *
     * @return \Illuminate\Support\Collection<int, CashMovement>
     */
    private function getStoreIncomeMovementsForRange(Carbon $start, Carbon $end)
    {
        $query = CashMovement::query()
            ->whereBetween('date', [$start, $end])
            ->where('is_deleted', false)
            ->where('category', CashMovement::CATEGORY_STORE)
            ->where('type', CashMovement::TYPE_INCOME)
            ->orderBy('date', 'asc');

        WarehouseQueryFilter::apply($query, 'warehouse_id');

        return $query->get();
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

        // 3. GASTOS OPERATIVOS (desglosados)
        // Se excluyen las compras de mercadería (INVENTORY_PURCHASE): son intercambios de activos
        // (caja → inventario), no gastos deducibles. Su impacto ya aparece en el Costo de Ventas.
        $expenseBreakdown = CashMovement::whereBetween('date', [$start, $end])
            ->operatingExpenses()
            ->where('is_deleted', false)
            ->selectRaw("
                SUM(CASE WHEN category = ? THEN amount ELSE 0 END) as administrative,
                SUM(CASE WHEN category = ? THEN amount ELSE 0 END) as store
            ", [
                CashMovement::CATEGORY_ADMINISTRATIVE,
                CashMovement::CATEGORY_STORE,
            ])
            ->first();

        $administrativeExpenses = (float) ($expenseBreakdown->administrative ?? 0);
        $storeExpenses = (float) ($expenseBreakdown->store ?? 0);
        $operatingExpenses = $administrativeExpenses + $storeExpenses;

        $grossProfit = $totalRevenue - (float) $costOfGoods;

        return [
            'period' => $start->format('d/m/Y').' - '.$end->format('d/m/Y'),
            'sales_revenue' => $totalRevenue,
            'cost_of_goods' => (float) $costOfGoods,
            'gross_profit' => $grossProfit,
            'administrative_expenses' => $administrativeExpenses,
            'store_expenses' => $storeExpenses,
            'operating_expenses' => $operatingExpenses,
            'net_utility' => $grossProfit - $operatingExpenses,
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
                'sort_month' => $month,
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

    /**
     * Reporte de ventas de un día: resumen, desglose por método y listado.
     *
     * @return array<string, mixed>
     */
    public function getDailySalesReport(string $date): array
    {
        $day = Carbon::parse($date);
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $salesQuery = Sale::query()
            ->whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details', 'customer'])
            ->orderBy('creation_time', 'desc');

        WarehouseQueryFilter::apply($salesQuery, 'warehouse_id');

        $sales = $salesQuery->get();
        $storeIncomes = $this->getStoreIncomeMovementsForRange($start, $end);

        $paymentBreakdown = $this->buildCajaIngresosBreakdown($sales, $storeIncomes);
        $totalAmount = array_sum(array_column($paymentBreakdown, 'amount'));
        $salesAmount = (float) $sales->sum('total_amount');
        $storeIncomesAmount = (float) $storeIncomes->sum('amount');
        $itemsSold = (int) $sales->sum(fn (Sale $sale) => $sale->details->sum('quantity'));
        $transactionCount = $sales->count() + $storeIncomes->count();

        return [
            'date' => $day->format('d/m/Y'),
            'date_iso' => $day->format('Y-m-d'),
            'summary' => [
                'total_amount' => round($totalAmount, 2),
                'total_sales' => round($salesAmount, 2),
                'total_store_incomes' => round($storeIncomesAmount, 2),
                'transaction_count' => $transactionCount,
                'items_sold' => $itemsSold,
                'average_ticket' => $transactionCount > 0
                    ? round($totalAmount / $transactionCount, 2)
                    : 0.0,
                'cash' => round($this->sumPaymentBreakdownByMethods($paymentBreakdown, ['CASH']), 2),
                'digital' => round($this->sumPaymentBreakdownByMethods(
                    $paymentBreakdown,
                    $this->digitalPaymentMethods(),
                ), 2),
            ],
            'payment_breakdown' => $paymentBreakdown,
            'hourly_chart' => $this->buildHourlyCajaChart($sales, $storeIncomes, $start, $end),
            'sales' => $this->buildDailyCajaEntries($sales, $storeIncomes),
        ];
    }

    /**
     * Reporte de ventas mensual: resumen, desglose diario y tendencia.
     *
     * @return array<string, mixed>
     */
    public function getMonthlySalesReport(string $month): array
    {
        $monthDate = Carbon::parse($month.'-01');
        $start = $monthDate->copy()->startOfMonth()->startOfDay();
        $end = $monthDate->copy()->endOfMonth()->endOfDay();

        $salesQuery = Sale::query()
            ->whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details'])
            ->orderBy('creation_time', 'asc');

        WarehouseQueryFilter::apply($salesQuery, 'warehouse_id');

        $sales = $salesQuery->get();
        $storeIncomes = $this->getStoreIncomeMovementsForRange($start, $end);

        $paymentBreakdown = $this->buildCajaIngresosBreakdown($sales, $storeIncomes);
        $totalAmount = array_sum(array_column($paymentBreakdown, 'amount'));
        $salesAmount = (float) $sales->sum('total_amount');
        $storeIncomesAmount = (float) $storeIncomes->sum('amount');
        $transactionCount = $sales->count() + $storeIncomes->count();
        $itemsSold = (int) $sales->sum(fn (Sale $sale) => $sale->details->sum('quantity'));
        $daysInMonth = $monthDate->daysInMonth;
        $dailyBreakdown = $this->buildDailyBreakdown($sales, $storeIncomes, $start, $end);
        $daysWithSales = count(array_filter(
            $dailyBreakdown,
            static fn (array $row): bool => ($row['transactions'] ?? 0) > 0,
        ));

        return [
            'month' => $monthDate->format('m-Y'),
            'month_label' => $this->formatSpanishMonthYear($monthDate),
            'month_iso' => $monthDate->format('Y-m'),
            'summary' => [
                'total_amount' => round($totalAmount, 2),
                'total_sales' => round($salesAmount, 2),
                'total_store_incomes' => round($storeIncomesAmount, 2),
                'transaction_count' => $transactionCount,
                'items_sold' => $itemsSold,
                'average_ticket' => $transactionCount > 0
                    ? round($totalAmount / $transactionCount, 2)
                    : 0.0,
                'average_daily' => $daysInMonth > 0
                    ? round($totalAmount / $daysInMonth, 2)
                    : 0.0,
                'days_with_sales' => $daysWithSales,
                'cash' => round($this->sumPaymentBreakdownByMethods($paymentBreakdown, ['CASH']), 2),
                'digital' => round($this->sumPaymentBreakdownByMethods(
                    $paymentBreakdown,
                    $this->digitalPaymentMethods(),
                ), 2),
            ],
            'payment_breakdown' => $paymentBreakdown,
            'daily_breakdown' => $dailyBreakdown,
            'daily_chart' => [
                'labels' => array_column($dailyBreakdown, 'date'),
                'amounts' => array_column($dailyBreakdown, 'total'),
            ],
        ];
    }

    /**
     * Ventas diarias agrupadas en un rango de fechas (inclusive).
     *
     * @return array<string, mixed>
     */
    public function getDailySalesPeriodReport(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $salesQuery = Sale::query()
            ->whereBetween('creation_time', [$start, $end])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details'])
            ->orderBy('creation_time', 'asc');

        WarehouseQueryFilter::apply($salesQuery, 'warehouse_id');

        $sales = $salesQuery->get();
        $storeIncomes = $this->getStoreIncomeMovementsForRange($start, $end);

        $paymentBreakdown = $this->buildCajaIngresosBreakdown($sales, $storeIncomes);
        $totalAmount = array_sum(array_column($paymentBreakdown, 'amount'));
        $salesAmount = (float) $sales->sum('total_amount');
        $storeIncomesAmount = (float) $storeIncomes->sum('amount');
        $transactionCount = $sales->count() + $storeIncomes->count();
        $itemsSold = (int) $sales->sum(fn (Sale $sale) => $sale->details->sum('quantity'));
        $dailyBreakdown = $this->buildDailyBreakdown($sales, $storeIncomes, $start, $end, true);
        $daysInRange = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $daysWithSales = count(array_filter(
            $dailyBreakdown,
            static fn (array $row): bool => ($row['transactions'] ?? 0) > 0,
        ));

        return [
            'period_label' => $start->format('d/m/Y').' — '.$end->format('d/m/Y'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'summary' => [
                'total_amount' => round($totalAmount, 2),
                'total_sales' => round($salesAmount, 2),
                'total_store_incomes' => round($storeIncomesAmount, 2),
                'transaction_count' => $transactionCount,
                'items_sold' => $itemsSold,
                'average_ticket' => $transactionCount > 0
                    ? round($totalAmount / $transactionCount, 2)
                    : 0.0,
                'average_daily' => $daysInRange > 0
                    ? round($totalAmount / $daysInRange, 2)
                    : 0.0,
                'days_with_sales' => $daysWithSales,
                'days_in_range' => $daysInRange,
                'cash' => round($this->sumPaymentBreakdownByMethods($paymentBreakdown, ['CASH']), 2),
                'digital' => round($this->sumPaymentBreakdownByMethods(
                    $paymentBreakdown,
                    $this->digitalPaymentMethods(),
                ), 2),
            ],
            'daily_breakdown' => $dailyBreakdown,
        ];
    }

    /**
     * Ingresos de caja = ventas + ingresos manuales de tienda (misma lógica que Control de Caja).
     *
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @param  \Illuminate\Support\Collection<int, CashMovement>  $storeIncomes
     * @return list<array{method: string, label: string, amount: float, count: int}>
     */
    private function buildCajaIngresosBreakdown($sales, $storeIncomes): array
    {
        $totals = [];

        foreach ($sales as $sale) {
            $payments = $sale->payments;

            if ($payments->isNotEmpty()) {
                foreach ($payments as $payment) {
                    $method = (string) $payment->method;
                    $totals[$method]['amount'] = ($totals[$method]['amount'] ?? 0) + (float) $payment->amount;
                    $totals[$method]['count'] = ($totals[$method]['count'] ?? 0) + 1;
                }

                continue;
            }

            $method = (string) $sale->payment_method;
            $totals[$method]['amount'] = ($totals[$method]['amount'] ?? 0) + (float) $sale->total_amount;
            $totals[$method]['count'] = ($totals[$method]['count'] ?? 0) + 1;
        }

        foreach ($storeIncomes as $movement) {
            $method = (string) $movement->payment_method;
            $totals[$method]['amount'] = ($totals[$method]['amount'] ?? 0) + (float) $movement->amount;
            $totals[$method]['count'] = ($totals[$method]['count'] ?? 0) + 1;
        }

        $breakdown = [];
        foreach ($totals as $method => $data) {
            $breakdown[] = [
                'method' => $method,
                'label' => $this->formatPaymentMethodLabel($method),
                'amount' => round((float) $data['amount'], 2),
                'count' => (int) $data['count'],
            ];
        }

        usort($breakdown, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $breakdown;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @param  \Illuminate\Support\Collection<int, CashMovement>  $storeIncomes
     * @return list<array<string, mixed>>
     */
    private function buildDailyCajaEntries($sales, $storeIncomes): array
    {
        $entries = [];

        foreach ($sales as $sale) {
            $entries[] = [
                'sort_at' => $sale->creation_time?->timestamp ?? 0,
                'id' => $sale->id,
                'source' => 'sale',
                'code' => $sale->code,
                'time' => $sale->creation_time?->format('h:i A') ?? '—',
                'customer' => $sale->customer?->name ?? 'Público General',
                'description' => null,
                'items_count' => (int) $sale->details->sum('quantity'),
                'total_amount' => (float) $sale->total_amount,
                'payment_method' => $sale->payment_method,
                'payment_label' => $this->formatPaymentMethodLabel($sale->payment_method),
            ];
        }

        foreach ($storeIncomes as $movement) {
            $movementDate = $movement->date instanceof Carbon
                ? $movement->date
                : Carbon::parse($movement->date);

            $entries[] = [
                'sort_at' => $movementDate->timestamp,
                'id' => $movement->id,
                'source' => 'income',
                'code' => 'ING',
                'time' => $movementDate->format('h:i A'),
                'customer' => '—',
                'description' => $movement->description,
                'items_count' => 0,
                'total_amount' => (float) $movement->amount,
                'payment_method' => $movement->payment_method,
                'payment_label' => $this->formatPaymentMethodLabel($movement->payment_method),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $b['sort_at'] <=> $a['sort_at']);

        return array_values(array_map(static function (array $entry): array {
            unset($entry['sort_at']);

            return $entry;
        }, $entries));
    }

    /**
     * @param  list<array{method: string, amount: float}>  $breakdown
     * @param  list<string>  $methods
     */
    private function sumPaymentBreakdownByMethods(array $breakdown, array $methods): float
    {
        return array_reduce(
            $breakdown,
            static function (float $carry, array $row) use ($methods): float {
                return in_array($row['method'], $methods, true)
                    ? $carry + (float) $row['amount']
                    : $carry;
            },
            0.0,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @param  \Illuminate\Support\Collection<int, CashMovement>  $storeIncomes
     * @return array{labels: list<string>, amounts: list<float>, counts: list<int>}
     */
    private function buildHourlyCajaChart($sales, $storeIncomes, Carbon $start, Carbon $end): array
    {
        $labels = [];
        $amounts = [];
        $counts = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = sprintf('%02d:00', $hour);
            $amounts[] = 0.0;
            $counts[] = 0;
        }

        foreach ($sales as $sale) {
            $hour = (int) ($sale->creation_time?->format('G') ?? 0);
            $amounts[$hour] += (float) $sale->total_amount;
            $counts[$hour]++;
        }

        foreach ($storeIncomes as $movement) {
            $movementDate = $movement->date instanceof Carbon
                ? $movement->date
                : Carbon::parse($movement->date);
            $hour = (int) $movementDate->format('G');
            $amounts[$hour] += (float) $movement->amount;
            $counts[$hour]++;
        }

        return [
            'labels' => $labels,
            'amounts' => array_map(static fn (float $value): float => round($value, 2), $amounts),
            'counts' => $counts,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @param  \Illuminate\Support\Collection<int, CashMovement>  $storeIncomes
     * @return list<array{date: string, day_of_week: string, transactions: int, total: float, cash: float, digital: float, sales_total: float, store_incomes_total: float}>
     */
    private function buildDailyBreakdown($sales, $storeIncomes, Carbon $start, Carbon $end, bool $includeYear = false): array
    {
        $bancosMethods = $this->digitalPaymentMethods();
        $byDay = [];
        $dateFormat = $includeYear ? 'd/m/Y' : 'd/m';

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $byDay[$key] = [
                'date' => $date->format($dateFormat),
                'day_of_week' => $this->formatSpanishWeekdayShort($date),
                'transactions' => 0,
                'total' => 0.0,
                'cash' => 0.0,
                'digital' => 0.0,
                'sales_total' => 0.0,
                'store_incomes_total' => 0.0,
            ];
        }

        foreach ($sales as $sale) {
            $key = $sale->creation_time?->format('Y-m-d');
            if ($key === null || ! isset($byDay[$key])) {
                continue;
            }

            $byDay[$key]['transactions']++;
            $byDay[$key]['total'] += (float) $sale->total_amount;
            $byDay[$key]['sales_total'] += (float) $sale->total_amount;
            $this->applyPaymentAmountsToDayRow(
                $byDay[$key],
                $sale->payments->isNotEmpty()
                    ? $sale->payments->map(fn ($payment) => [
                        'method' => (string) $payment->method,
                        'amount' => (float) $payment->amount,
                    ])->all()
                    : [[
                        'method' => (string) $sale->payment_method,
                        'amount' => (float) $sale->total_amount,
                    ]],
                $bancosMethods,
            );
        }

        foreach ($storeIncomes as $movement) {
            $movementDate = $movement->date instanceof Carbon
                ? $movement->date
                : Carbon::parse($movement->date);
            $key = $movementDate->format('Y-m-d');

            if (! isset($byDay[$key])) {
                continue;
            }

            $amount = (float) $movement->amount;
            $byDay[$key]['transactions']++;
            $byDay[$key]['total'] += $amount;
            $byDay[$key]['store_incomes_total'] += $amount;
            $this->applyPaymentAmountsToDayRow(
                $byDay[$key],
                [[
                    'method' => (string) $movement->payment_method,
                    'amount' => $amount,
                ]],
                $bancosMethods,
            );
        }

        return array_values(array_map(static function (array $row): array {
            $row['total'] = round($row['total'], 2);
            $row['cash'] = round($row['cash'], 2);
            $row['digital'] = round($row['digital'], 2);
            $row['sales_total'] = round($row['sales_total'], 2);
            $row['store_incomes_total'] = round($row['store_incomes_total'], 2);

            return $row;
        }, $byDay));
    }

    /**
     * @param  array{cash: float, digital: float}  $row
     * @param  list<array{method: string, amount: float}>  $payments
     * @param  list<string>  $bancosMethods
     */
    private function applyPaymentAmountsToDayRow(array &$row, array $payments, array $bancosMethods): void
    {
        foreach ($payments as $payment) {
            $method = $payment['method'];
            $amount = (float) $payment['amount'];

            if ($method === 'CASH') {
                $row['cash'] += $amount;
            } elseif (in_array($method, $bancosMethods, true)) {
                $row['digital'] += $amount;
            }
        }
    }

    private function formatPaymentMethodLabel(?string $method): string
    {
        return match (strtoupper((string) $method)) {
            'CASH' => 'Efectivo',
            'YAPE' => 'Yape',
            'PLIN' => 'Plin',
            'CARD' => 'Tarjeta',
            'TRANSFER' => 'Transferencia',
            'MIXTO' => 'Mixto',
            default => $method !== null && trim($method) !== '' ? $method : '—',
        };
    }

    private function formatSpanishMonthYear(Carbon $date): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($months[(int) $date->format('n')] ?? $date->format('F')).' '.$date->format('Y');
    }

    private function formatSpanishWeekdayShort(Carbon $date): string
    {
        $days = [
            0 => 'Dom', 1 => 'Lun', 2 => 'Mar', 3 => 'Mié',
            4 => 'Jue', 5 => 'Vie', 6 => 'Sáb',
        ];

        return $days[(int) $date->format('w')] ?? $date->format('D');
    }
}
