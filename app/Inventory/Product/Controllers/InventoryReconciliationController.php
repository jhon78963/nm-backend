<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Concerns\AssertsInventoryMasterMatchesColorPivotSum;
use App\Inventory\Concerns\ProvidesInventoryLockSortKey;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Requests\InventoryReconciliationReplaceColorRequest;
use App\Inventory\Product\Requests\InventoryReconciliationSearchRequest;
use App\Inventory\Product\Requests\InventoryReconciliationUpdateRequest;
use App\Inventory\Product\Resources\InventoryReconciliationProductResource;
use App\Inventory\Product\Services\ProductService;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Inventory\Product\Services\ProductSizeService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryReconciliationController extends Controller
{
    use AssertsInventoryMasterMatchesColorPivotSum;
    use ProvidesInventoryLockSortKey;

    private const SEARCH_LIMIT = 20;

    /** Motivo en `product_histories.reason` para ajustes manuales del cuadre físico */
    private const AUDIT_REASON_PHYSICAL_COUNT = 'Cuadre de inventario físico';

    private const AUDIT_REASON_REPLACE_COLOR_LABEL = 'Cuadre de inventario físico — sustitución de etiqueta de color';

    public function __construct(
        protected ProductService $productService,
        protected ProductSizeService $productSizeService,
        protected ProductSizeColorService $productSizeColorService,
    ) {}

    public function search(InventoryReconciliationSearchRequest $request): JsonResponse
    {
        $q = trim($request->validated('q'));

        $products = Product::query()
            ->where('is_deleted', false)
            ->where(function ($query) use ($q): void {
                $query
                    ->where('name', 'ilike', '%'.$q.'%')
                    ->orWhere('barcode', $q)
                    ->orWhereHas(
                        'productSizes',
                        static fn ($ps) => $ps->where('barcode', $q),
                    );

                if (ctype_digit($q)) {
                    $query->orWhere('id', (int) $q);
                }
            })
            ->with([
                'productSizes' => static fn ($rel) => $rel
                    ->orderBy('size_id'),
                'productSizes.size',
                'productSizes.colors',
            ])
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return response()->json([
            'products' => InventoryReconciliationProductResource::collection($products),
        ]);
    }

    public function update(
        InventoryReconciliationUpdateRequest $request,
        Product $product,
    ): JsonResponse {
        $this->productService->validate($product, 'Product');

        $validated = $request->validated();
        $freshProduct = null;

        DB::transaction(function () use ($product, $validated, &$freshProduct): void {
            $touchedMasterIds = [];

            $sizeRows = collect($validated['sizes']);
            $productSizes = ProductSize::query()
                ->where('product_id', $product->id)
                ->whereIn('id', $sizeRows->pluck('id')->all())
                ->get()
                ->keyBy('id');

            $ordered = $sizeRows->sortBy(function (array $row) use ($productSizes, $product): string {
                /** @var ProductSize|null $ps */
                $ps = $productSizes->get($row['id']);

                return $ps !== null
                    ? $this->getInventoryLockSortKey((int) $product->id, (int) $ps->size_id)
                    : (string) $row['id'];
            });

            foreach ($ordered as $sizePayload) {
                /** @var ProductSize|null $productSize */
                $productSize = $productSizes->get($sizePayload['id']);
                if ($productSize === null) {
                    continue;
                }

                $colors = isset($sizePayload['colors']) && is_array($sizePayload['colors'])
                    ? $sizePayload['colors']
                    : [];

                if ($colors !== []) {
                    $colorRows = collect($colors)->sortBy(
                        static fn ($c) => (int) ($c['colorId'] ?? 0),
                    );
                    foreach ($colorRows as $colorPayload) {
                        $this->productSizeColorService->set(
                            $productSize,
                            (int) $colorPayload['colorId'],
                            ['stock' => (int) $colorPayload['stock']],
                            updateMaster: true,
                            auditReason: self::AUDIT_REASON_PHYSICAL_COUNT,
                        );
                    }

                    /*
                     * Si la BD ya venía descuadrada (maestro ≠ suma de colores), los deltas de color
                     * pueden ser 0 y el maestro no se corrige solo. Forzamos coherencia vía el
                     * servicio de talla (stock absoluto = suma de pivotes), sin reimplementar lógica.
                     */
                    $masterRow = DB::table('product_size')
                        ->where('id', $productSize->id)
                        ->lockForUpdate()
                        ->first();

                    if ($masterRow !== null) {
                        $sumColors = (int) DB::table('product_size_color')
                            ->where('product_size_id', $productSize->id)
                            ->sum('stock');

                        $this->productSizeService->set(
                            $product,
                            (int) $masterRow->size_id,
                            array_merge(
                                [
                                    'stock' => $sumColors,
                                ],
                                $this->mergeSizePayloadPricingAndBarcode($sizePayload, $masterRow),
                            ),
                            self::AUDIT_REASON_PHYSICAL_COUNT,
                        );
                    }

                    $touchedMasterIds[] = (int) $productSize->id;
                } elseif (array_key_exists('stock', $sizePayload)) {
                    $this->productSizeService->set(
                        $product,
                        (int) $productSize->size_id,
                        $this->buildMasterOnlySizeData($productSize, $sizePayload),
                        self::AUDIT_REASON_PHYSICAL_COUNT,
                    );
                    $touchedMasterIds[] = (int) $productSize->id;
                }
            }

            foreach (array_unique($touchedMasterIds) as $psId) {
                $this->assertMasterMatchesColorsSum((int) $psId);
            }

            $freshProduct = $product->fresh()->load([
                'productSizes' => static fn ($rel) => $rel->orderBy('size_id'),
                'productSizes.size',
                'productSizes.colors',
            ]);
        });

        return response()->json([
            'message' => 'Inventario actualizado correctamente.',
            'product' => $freshProduct !== null
                ? new InventoryReconciliationProductResource($freshProduct)
                : null,
        ]);
    }

    public function replaceColor(
        InventoryReconciliationReplaceColorRequest $request,
        Product $product,
        ProductSize $productSize,
    ): JsonResponse {
        $this->productService->validate($product, 'Product');

        if ((int) $productSize->product_id !== (int) $product->id) {
            abort(404);
        }

        $fromColorId = (int) $request->validated('fromColorId');
        $toColorId = (int) $request->validated('toColorId');

        try {
            DB::transaction(function () use ($productSize, $fromColorId, $toColorId): void {
                $this->productSizeColorService->replacePivotColor(
                    $productSize,
                    $fromColorId,
                    $toColorId,
                    self::AUDIT_REASON_REPLACE_COLOR_LABEL,
                );
                $this->assertMasterMatchesColorsSum((int) $productSize->id);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $freshProduct = $product->fresh()->load([
            'productSizes' => static fn ($rel) => $rel->orderBy('size_id'),
            'productSizes.size',
            'productSizes.colors',
        ]);

        return response()->json([
            'message' => 'Color de la variante actualizado; el stock se mantuvo en la nueva etiqueta.',
            'product' => new InventoryReconciliationProductResource($freshProduct),
        ]);
    }

    /**
     * @param  array<string, mixed>  $sizePayload
     * @param  object{barcode: ?string, purchase_price: mixed, sale_price: mixed, min_sale_price: mixed}  $masterRow
     * @return array<string, mixed>
     */
    private function mergeSizePayloadPricingAndBarcode(array $sizePayload, object $masterRow): array
    {
        return [
            'barcode' => array_key_exists('barcode', $sizePayload)
                ? $sizePayload['barcode']
                : $masterRow->barcode,
            'purchasePrice' => array_key_exists('purchasePrice', $sizePayload)
                ? $sizePayload['purchasePrice']
                : $masterRow->purchase_price,
            'salePrice' => array_key_exists('salePrice', $sizePayload)
                ? $sizePayload['salePrice']
                : $masterRow->sale_price,
            'minSalePrice' => array_key_exists('minSalePrice', $sizePayload)
                ? $sizePayload['minSalePrice']
                : $masterRow->min_sale_price,
        ];
    }

    /**
     * @param  array<string, mixed>  $sizePayload
     * @return array<string, mixed>
     */
    private function buildMasterOnlySizeData(ProductSize $productSize, array $sizePayload): array
    {
        return [
            'stock' => (int) $sizePayload['stock'],
            'barcode' => array_key_exists('barcode', $sizePayload)
                ? $sizePayload['barcode']
                : $productSize->barcode,
            'purchasePrice' => array_key_exists('purchasePrice', $sizePayload)
                ? $sizePayload['purchasePrice']
                : $productSize->purchase_price,
            'salePrice' => array_key_exists('salePrice', $sizePayload)
                ? $sizePayload['salePrice']
                : $productSize->sale_price,
            'minSalePrice' => array_key_exists('minSalePrice', $sizePayload)
                ? $sizePayload['minSalePrice']
                : $productSize->min_sale_price,
        ];
    }
}
