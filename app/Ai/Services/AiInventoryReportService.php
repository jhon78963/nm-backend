<?php

namespace App\Ai\Services;

use App\Inventories\Products\Models\Product;
use App\Reports\Management\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiInventoryReportService
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly AiProductContextService $aiProductContextService,
        private readonly AiEngineService $aiEngineService,
    ) {}

    /**
     * Inventario por producto/talla con predicciones IA masivas.
     *
     * @return array{
     *     products: list<array<string, mixed>>,
     *     horizon_days: int,
     *     ai_summary: array{processed: int, errors: int, dead_stock_count: int}
     * }
     */
    public function build(Request $request, int $horizonDays = 30): array
    {
        $inventory = $this->reportService->getProductsInventoryReport();
        $productIds = array_map(static fn (array $row): int => (int) $row['id'], $inventory);

        $productsById = Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $bulkItems = [];

        foreach ($inventory as $row) {
            $product = $productsById->get((int) $row['id']);
            if ($product === null) {
                continue;
            }

            $pricePayload = null;
            try {
                $pricePayload = $this->aiProductContextService->buildPricePayload($product, $request);
            } catch (RuntimeException $exception) {
                Log::debug('AI bulk: precio omitido', [
                    'product_id' => $product->id,
                    'reason' => $exception->getMessage(),
                ]);
            }

            $demandPayload = $this->aiProductContextService->buildDemandPayload(
                $product,
                $request,
                $horizonDays,
            );

            $bulkItems[] = [
                'product_id' => (int) $product->id,
                'price' => $pricePayload,
                'demand' => $demandPayload,
            ];
        }

        $aiByProductId = $this->aiEngineService->predictBulk($bulkItems);

        $deadStockCount = 0;
        $errors = 0;

        $enriched = array_map(function (array $productRow) use ($aiByProductId, &$deadStockCount, &$errors): array {
            $productId = (int) $productRow['id'];
            $ai = $aiByProductId[$productId] ?? null;

            if ($ai !== null) {
                if (($ai['is_dead_stock'] ?? false) === true) {
                    $deadStockCount++;
                }
                if (($ai['price_error'] ?? null) !== null || ($ai['demand_error'] ?? null) !== null) {
                    $errors++;
                }
            }

            $productRow['ai'] = $ai ?? [
                'suggested_price' => null,
                'suggested_purchase_quantity' => null,
                'projected_sales' => null,
                'is_dead_stock' => false,
                'price_error' => 'Sin predicción IA',
                'demand_error' => null,
            ];

            return $productRow;
        }, $inventory);

        return [
            'products' => $enriched,
            'horizon_days' => $horizonDays,
            'ai_summary' => [
                'processed' => count($bulkItems),
                'errors' => $errors,
                'dead_stock_count' => $deadStockCount,
            ],
        ];
    }
}
