<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\InventoryLedger\Support\WarehouseIdForInventoryResolver;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Requests\ProductAddRequest;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductSizeColorController extends Controller
{
    public function __construct(
        protected ProductSizeColorService $productSizeColorService
    ) {}

    public function add(
        ProductAddRequest $request,
        ProductSize $productSize,
        int $colorId
    ): JsonResponse {
        $this->assertActorCanAccessProductSizeWarehouse($productSize);

        return DB::transaction(function () use ($request, $productSize, $colorId) {
            $this->productSizeColorService->set(
                $productSize,
                $colorId,
                $request->validated()
            );

            return response()->json(['message' => 'Color added successfully.'], 201);
        });
    }

    public function modify(
        ProductAddRequest $request,
        ProductSize $productSize,
        int $colorId
    ): JsonResponse {
        $this->assertActorCanAccessProductSizeWarehouse($productSize);

        return DB::transaction(function () use ($request, $productSize, $colorId) {
            $this->productSizeColorService->set(
                $productSize,
                $colorId,
                $request->validated()
            );

            return response()->json(['message' => 'Color modified successfully.'], 200);
        });
    }

    public function remove(
        ProductSize $productSize,
        int $colorId
    ): JsonResponse {
        $this->assertActorCanAccessProductSizeWarehouse($productSize);

        return DB::transaction(function () use ($productSize, $colorId) {
            $this->productSizeColorService->remove(
                $productSize,
                $colorId
            );

            return response()->json(['message' => 'Color removed successfully.'], 200);
        });
    }

    /**
     * ProductSize no usa WarehouseScope; el binding por PK es global.
     * Verifica que el producto padre pertenezca a un almacén accesible por el actor.
     *
     * @throws AuthorizationException
     */
    private function assertActorCanAccessProductSizeWarehouse(ProductSize $productSize): void
    {
        $warehouseId = (int) $productSize->loadMissing('product')->product->warehouse_id;

        WarehouseIdForInventoryResolver::assertUserCanAccessWarehouse($warehouseId);
    }
}
