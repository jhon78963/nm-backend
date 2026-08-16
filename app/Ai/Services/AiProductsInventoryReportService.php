<?php

namespace App\Ai\Services;

use App\Report\Services\ReportService;
use RuntimeException;

class AiProductsInventoryReportService
{
    private const BULK_CHUNK_SIZE = 500;

    public function __construct(
        private readonly ReportService $reportService,
        private readonly AiProductContextService $contextService,
        private readonly AiEngineClient $engineClient,
    ) {}

    /**
     * @return array{
     *   products: array<int, array<string, mixed>>,
     *   horizon_days: int,
     *   ai_summary: array{processed: int, errors: int, dead_stock_count: int}
     * }
     */
    public function build(int $horizonDays, bool $canViewPurchasePrice): array
    {
        $products = $this->reportService->getProductsInventoryReport();
        $productIds = array_map(static fn (array $product): int => (int) $product['id'], $products);

        $contextMap = $this->contextService->buildContextMapForProductIds(
            $productIds,
            $canViewPurchasePrice,
        );

        $bulkItems = [];

        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $context = $contextMap[$productId] ?? null;

            if ($context === null) {
                continue;
            }

            $bulkItems[] = [
                'product_id' => $productId,
                'price' => $this->contextService->toPriceEnginePayload($context),
                'demand' => $this->contextService->toDemandEnginePayload($context, $horizonDays),
            ];
        }

        $aiByProductId = $this->runBulkPredictions($bulkItems);

        $deadStockCount = 0;
        $totalErrors = 0;

        $enrichedProducts = array_map(function (array $product) use ($aiByProductId, &$deadStockCount, &$totalErrors): array {
            $productId = (int) $product['id'];
            $ai = $aiByProductId[$productId] ?? null;

            if ($ai === null) {
                return $product;
            }

            if ($ai['is_dead_stock']) {
                $deadStockCount++;
            }

            if ($ai['price_error'] !== null || $ai['demand_error'] !== null) {
                $totalErrors++;
            }

            $product['ai'] = $ai;

            return $product;
        }, $products);

        return [
            'products' => $enrichedProducts,
            'horizon_days' => $horizonDays,
            'ai_summary' => [
                'processed' => count($bulkItems),
                'errors' => $totalErrors,
                'dead_stock_count' => $deadStockCount,
            ],
        ];
    }

    /**
     * @param  list<array{product_id:int, price:array<string, mixed>, demand:array<string, mixed>}>  $bulkItems
     * @return array<int, array<string, mixed>>
     */
    private function runBulkPredictions(array $bulkItems): array
    {
        $results = [];

        foreach (array_chunk($bulkItems, self::BULK_CHUNK_SIZE) as $chunk) {
            try {
                $response = $this->engineClient->predictBulk(['items' => $chunk]);
            } catch (RuntimeException $exception) {
                foreach ($chunk as $item) {
                    $productId = (int) $item['product_id'];
                    $results[$productId] = [
                        'suggested_price' => null,
                        'suggested_min_price' => null,
                        'suggested_purchase_quantity' => null,
                        'projected_sales' => null,
                        'is_dead_stock' => false,
                        'price_error' => $exception->getMessage(),
                        'demand_error' => $exception->getMessage(),
                    ];
                }

                continue;
            }

            foreach ($response['items'] ?? [] as $item) {
                $productId = (int) ($item['product_id'] ?? 0);

                if ($productId <= 0) {
                    continue;
                }

                $results[$productId] = [
                    'suggested_price' => $item['suggested_price'] ?? null,
                    'suggested_min_price' => $item['suggested_min_price'] ?? null,
                    'suggested_purchase_quantity' => $item['suggested_purchase_quantity'] ?? null,
                    'projected_sales' => $item['projected_sales'] ?? null,
                    'is_dead_stock' => (bool) ($item['is_dead_stock'] ?? false),
                    'price_error' => $item['price_error'] ?? null,
                    'demand_error' => $item['demand_error'] ?? null,
                ];
            }
        }

        return $results;
    }
}
