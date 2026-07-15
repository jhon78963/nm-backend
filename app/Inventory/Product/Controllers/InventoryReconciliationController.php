<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Concerns\ProvidesInventoryLockSortKey;
use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Requests\InventoryReconciliationReplaceColorRequest;
use App\Inventory\Product\Requests\InventoryReconciliationSearchRequest;
use App\Inventory\Product\Requests\InventoryReconciliationUpdateRequest;
use App\Inventory\Product\Resources\InventoryReconciliationProductResource;
use App\Inventory\Product\Services\InventoryReconciliationPosSalesService;
use App\Inventory\Product\Services\ProductService;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Inventory\Product\Services\ProductSizeService;
use App\Inventory\Warehouse\Models\Warehouse;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class InventoryReconciliationController extends Controller
{
    use ProvidesInventoryLockSortKey;

    private const SEARCH_LIMIT = 20;

    /** Motivo en `product_histories.reason` para ajustes manuales del cuadre físico */
    private const AUDIT_REASON_PHYSICAL_COUNT = 'Cuadre de inventario físico';

    private const AUDIT_REASON_REPLACE_COLOR_LABEL = 'Cuadre de inventario físico — sustitución de etiqueta de color';

    public function __construct(
        protected ProductService $productService,
        protected ProductSizeService $productSizeService,
        protected ProductSizeColorService $productSizeColorService,
        protected InventoryMovementService $inventoryMovementService,
        protected InventoryReconciliationPosSalesService $posSalesService,
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
                'gender',
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

    public function posSalesSince(Product $product): JsonResponse
    {
        $this->productService->validate($product, 'Product');

        return response()->json(
            $this->posSalesService->summarizeForProduct($product),
        );
    }

    public function update(
        InventoryReconciliationUpdateRequest $request,
        Product $product,
    ): JsonResponse {
        $this->productService->validate($product, 'Product');

        $validated = $request->validated();
        $warehouseId = (int) ($product->warehouse_id ?? 0);
        if ($warehouseId < 1) {
            throw new InvalidArgumentException('El producto no tiene un almacén configurado para la reconciliación');
        }

        $freshProduct = null;

        try {
            DB::transaction(function () use ($product, $validated, $warehouseId, &$freshProduct): void {
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
                            $colorId = (int) $colorPayload['colorId'];
                            $stock = (int) $colorPayload['stock'];

                            $this->productSizeColorService->set(
                                $productSize,
                                $colorId,
                                ['stock' => $stock],
                                true,
                                self::AUDIT_REASON_PHYSICAL_COUNT,
                            );
                        }

                        if (! $productSize->relationLoaded('product')) {
                            $productSize->load('product');
                        }

                        $this->productSizeColorService->syncMasterStockToColorVariantsSum($productSize);
                    } elseif (array_key_exists('stock', $sizePayload)) {
                        $this->recordReconciliationMovement(
                            $warehouseId,
                            (int) $product->id,
                            (int) $productSize->id,
                            null,
                            (int) $sizePayload['stock'],
                        );
                    }

                    $this->applySizeCatalogFromReconciliationPayload($product, $productSize, $sizePayload);
                }

                $freshProduct = $product->fresh()->load([
                    'productSizes' => static fn ($rel) => $rel->orderBy('size_id'),
                    'productSizes.size',
                    'productSizes.colors',
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->apiErrorResponse($e, 422, [
                'message' => 'No se pudo reconciliar el inventario.',
            ]);
        }

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
            });
        } catch (\Exception $e) {
            return $this->apiErrorResponse($e, 422);
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

    private function recordReconciliationMovement(
        int $warehouseId,
        int $productId,
        int $productSizeId,
        ?int $colorId,
        int $physicalQuantity,
    ): void {
        if ($warehouseId < 1) {
            throw new InvalidArgumentException('El producto no tiene un almacén configurado para la reconciliación');
        }

        $tenantId = (int) Warehouse::query()->findOrFail($warehouseId)->tenant_id;

        $this->inventoryMovementService->reconcileToPhysicalQuantity(new InventoryMovementDTO(
            tenantId: $tenantId,
            warehouseId: $warehouseId,
            productSizeId: $productSizeId,
            colorId: $colorId,
            direction: InventoryMovementDirection::In,
            quantity: 1,
            movementType: InventoryMovementType::Reconciliation,
            referenceType: Product::class,
            referenceId: $productId,
            createdByUserId: Auth::id(),
        ), $physicalQuantity);
    }

    /**
     * Persiste código de barras y precios de la talla enviados en el cuadre (fila product_size).
     * Se fusionan con lo existente para no borrar campos no enviados en el payload.
     */
    private function applySizeCatalogFromReconciliationPayload(Product $product, ProductSize $productSize, array $sizePayload): void
    {
        $touched = false;
        foreach (['barcode', 'purchasePrice', 'salePrice', 'minSalePrice'] as $key) {
            if (array_key_exists($key, $sizePayload)) {
                $touched = true;
                break;
            }
        }

        if (! $touched) {
            return;
        }

        $row = DB::table('product_size')
            ->where('id', $productSize->id)
            ->where('product_id', $product->id)
            ->first();

        if ($row === null) {
            return;
        }

        $data = [
            'barcode' => array_key_exists('barcode', $sizePayload)
                ? $sizePayload['barcode']
                : $row->barcode,
            'purchasePrice' => array_key_exists('purchasePrice', $sizePayload)
                ? $sizePayload['purchasePrice']
                : $row->purchase_price,
            'salePrice' => array_key_exists('salePrice', $sizePayload)
                ? $sizePayload['salePrice']
                : $row->sale_price,
            'minSalePrice' => array_key_exists('minSalePrice', $sizePayload)
                ? $sizePayload['minSalePrice']
                : $row->min_sale_price,
        ];

        $this->productSizeService->set(
            $product,
            (int) $productSize->size_id,
            $data,
            self::AUDIT_REASON_PHYSICAL_COUNT,
        );
    }
}
