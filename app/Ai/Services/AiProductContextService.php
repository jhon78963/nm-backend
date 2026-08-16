<?php

namespace App\Ai\Services;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AiProductContextService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly AiStockAgingEvaluator $stockAgingEvaluator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildContext(Product $product, bool $canViewCost): array
    {
        $this->productService->validate($product, 'Product');

        $row = DB::selectOne(
            <<<'SQL'
            WITH primary_size AS (
                SELECT DISTINCT ON (product_id)
                    product_id,
                    purchase_price,
                    sale_price
                FROM product_size
                WHERE product_id = ?
                ORDER BY product_id, id
            ),
            sales_30d AS (
                SELECT COALESCE(SUM(sd.quantity), 0)::integer AS sales_last_month
                FROM sale_details sd
                INNER JOIN sales s ON s.id = sd.sale_id
                WHERE sd.product_id = ?
                  AND s.status = 'COMPLETED'
                  AND s.is_deleted = false
                  AND s.creation_time >= NOW() - INTERVAL '30 days'
            ),
            sales_all AS (
                SELECT
                    COALESCE(SUM(sd.quantity), 0)::integer AS total_sales_all_time,
                    MAX(s.creation_time) AS last_sale_at
                FROM sale_details sd
                INNER JOIN sales s ON s.id = sd.sale_id
                WHERE sd.product_id = ?
                  AND s.status = 'COMPLETED'
                  AND s.is_deleted = false
            ),
            stock_totals AS (
                SELECT COALESCE(SUM(quantity), 0)::integer AS current_stock
                FROM inventory_balances
                WHERE product_id = ?
                  AND color_id IS NULL
            )
            SELECT
                p.id AS product_id,
                p.name AS product_name,
                ps.purchase_price::float AS current_cost,
                ps.sale_price::float AS sale_price,
                g.name AS category,
                COALESCE(s30.sales_last_month, 0) AS sales_last_month,
                COALESCE(st.current_stock, 0) AS current_stock,
                GREATEST(0, EXTRACT(DAY FROM NOW() - p.creation_time))::integer AS product_age_days,
                CASE
                    WHEN sa.last_sale_at IS NULL THEN GREATEST(0, EXTRACT(DAY FROM NOW() - p.creation_time))::integer
                    ELSE GREATEST(0, EXTRACT(DAY FROM NOW() - sa.last_sale_at))::integer
                END AS days_since_last_sale,
                COALESCE(sa.total_sales_all_time, 0) AS total_sales_all_time
            FROM products p
            INNER JOIN genders g ON g.id = p.gender_id
            INNER JOIN primary_size ps ON ps.product_id = p.id
            LEFT JOIN sales_30d s30 ON true
            LEFT JOIN sales_all sa ON true
            LEFT JOIN stock_totals st ON true
            WHERE p.id = ?
              AND p.is_deleted = false
            SQL,
            [
                $product->id,
                $product->id,
                $product->id,
                $product->id,
                $product->id,
            ],
        );

        if ($row === null) {
            throw new NotFoundHttpException('Producto no encontrado para contexto de IA.');
        }

        $aging = $this->stockAgingEvaluator->evaluate([
            'product_age_days' => (int) $row->product_age_days,
            'days_since_last_sale' => (int) $row->days_since_last_sale,
            'sales_last_month' => (int) $row->sales_last_month,
            'current_stock' => (int) $row->current_stock,
            'total_sales_all_time' => (int) $row->total_sales_all_time,
        ]);

        return [
            'product_id' => (int) $row->product_id,
            'product_name' => (string) $row->product_name,
            'current_cost' => $canViewCost ? (float) $row->current_cost : 0.0,
            'category' => (string) $row->category,
            'sales_last_month' => (int) $row->sales_last_month,
            'current_stock' => (int) $row->current_stock,
            'sale_price' => (float) $row->sale_price,
            'can_view_cost' => $canViewCost,
            'product_age_days' => (int) $row->product_age_days,
            'days_since_last_sale' => (int) $row->days_since_last_sale,
            'total_sales_all_time' => (int) $row->total_sales_all_time,
            'is_dead_stock' => $aging['is_dead_stock'],
            'dead_stock_tier' => $aging['dead_stock_tier'],
            'dead_stock_label' => $aging['dead_stock_label'],
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function buildContextMapForProductIds(array $productIds, bool $canViewCost): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $productIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $rows = DB::select(
            <<<SQL
            WITH primary_size AS (
                SELECT DISTINCT ON (product_id)
                    product_id,
                    purchase_price,
                    sale_price
                FROM product_size
                WHERE product_id IN ({$placeholders})
                ORDER BY product_id, id
            ),
            sales_30d AS (
                SELECT
                    sd.product_id,
                    COALESCE(SUM(sd.quantity), 0)::integer AS sales_last_month
                FROM sale_details sd
                INNER JOIN sales s ON s.id = sd.sale_id
                WHERE sd.product_id IN ({$placeholders})
                  AND s.status = 'COMPLETED'
                  AND s.is_deleted = false
                  AND s.creation_time >= NOW() - INTERVAL '30 days'
                GROUP BY sd.product_id
            ),
            sales_all AS (
                SELECT
                    sd.product_id,
                    COALESCE(SUM(sd.quantity), 0)::integer AS total_sales_all_time,
                    MAX(s.creation_time) AS last_sale_at
                FROM sale_details sd
                INNER JOIN sales s ON s.id = sd.sale_id
                WHERE sd.product_id IN ({$placeholders})
                  AND s.status = 'COMPLETED'
                  AND s.is_deleted = false
                GROUP BY sd.product_id
            ),
            stock_totals AS (
                SELECT
                    product_id,
                    COALESCE(SUM(quantity), 0)::integer AS current_stock
                FROM inventory_balances
                WHERE product_id IN ({$placeholders})
                  AND color_id IS NULL
                GROUP BY product_id
            )
            SELECT
                p.id AS product_id,
                p.name AS product_name,
                ps.purchase_price::float AS current_cost,
                ps.sale_price::float AS sale_price,
                g.name AS category,
                COALESCE(s30.sales_last_month, 0) AS sales_last_month,
                COALESCE(st.current_stock, 0) AS current_stock,
                GREATEST(0, EXTRACT(DAY FROM NOW() - p.creation_time))::integer AS product_age_days,
                CASE
                    WHEN sa.last_sale_at IS NULL THEN GREATEST(0, EXTRACT(DAY FROM NOW() - p.creation_time))::integer
                    ELSE GREATEST(0, EXTRACT(DAY FROM NOW() - sa.last_sale_at))::integer
                END AS days_since_last_sale,
                COALESCE(sa.total_sales_all_time, 0) AS total_sales_all_time
            FROM products p
            INNER JOIN genders g ON g.id = p.gender_id
            INNER JOIN primary_size ps ON ps.product_id = p.id
            LEFT JOIN sales_30d s30 ON s30.product_id = p.id
            LEFT JOIN sales_all sa ON sa.product_id = p.id
            LEFT JOIN stock_totals st ON st.product_id = p.id
            WHERE p.id IN ({$placeholders})
              AND p.is_deleted = false
            SQL,
            array_merge($productIds, $productIds, $productIds, $productIds, $productIds),
        );

        $contextMap = [];

        foreach ($rows as $row) {
            $aging = $this->stockAgingEvaluator->evaluate([
                'product_age_days' => (int) $row->product_age_days,
                'days_since_last_sale' => (int) $row->days_since_last_sale,
                'sales_last_month' => (int) $row->sales_last_month,
                'current_stock' => (int) $row->current_stock,
                'total_sales_all_time' => (int) $row->total_sales_all_time,
            ]);

            $productId = (int) $row->product_id;

            $contextMap[$productId] = [
                'product_id' => $productId,
                'product_name' => (string) $row->product_name,
                'current_cost' => $canViewCost ? (float) $row->current_cost : 0.0,
                'category' => (string) $row->category,
                'sales_last_month' => (int) $row->sales_last_month,
                'current_stock' => (int) $row->current_stock,
                'sale_price' => (float) $row->sale_price,
                'can_view_cost' => $canViewCost,
                'product_age_days' => (int) $row->product_age_days,
                'days_since_last_sale' => (int) $row->days_since_last_sale,
                'total_sales_all_time' => (int) $row->total_sales_all_time,
                'is_dead_stock' => $aging['is_dead_stock'],
                'dead_stock_tier' => $aging['dead_stock_tier'],
                'dead_stock_label' => $aging['dead_stock_label'],
            ];
        }

        return $contextMap;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function toPriceEnginePayload(array $context): array
    {
        return [
            'product_id' => (int) $context['product_id'],
            'current_cost' => (float) $context['current_cost'],
            'current_sale_price' => (float) $context['sale_price'],
            'category' => (string) $context['category'],
            'sales_last_month' => (int) $context['sales_last_month'],
            'current_stock' => (int) $context['current_stock'],
            'product_age_days' => (int) $context['product_age_days'],
            'days_since_last_sale' => (int) $context['days_since_last_sale'],
            'total_sales_all_time' => (int) $context['total_sales_all_time'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function toDemandEnginePayload(array $context, int $horizonDays): array
    {
        return [
            'product_id' => (int) $context['product_id'],
            'current_stock' => (int) $context['current_stock'],
            'horizon_days' => $horizonDays,
            'sales_last_month' => (int) $context['sales_last_month'],
            'product_age_days' => (int) $context['product_age_days'],
            'days_since_last_sale' => (int) $context['days_since_last_sale'],
            'total_sales_all_time' => (int) $context['total_sales_all_time'],
        ];
    }
}
