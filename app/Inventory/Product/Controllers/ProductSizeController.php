<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Requests\ProductAddRequest;
use App\Inventory\Product\Services\ProductSizeService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use DB;

class ProductSizeController extends Controller
{
    protected ProductSizeService $productSizeService;

    public function __construct(ProductSizeService $productSizeService)
    {
        $this->productSizeService = $productSizeService;
    }

    public function add(
        ProductAddRequest $request,
        Product $product,
        int $sizeId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productSizeService->add(
                $product,
                $sizeId,
                $request->validated(),
            );
            DB::commit();
            return response()->json(['message' => 'Size added.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function get(int $productId, int $sizeId): JsonResponse
    {
        $productSize = $this->getId($productId, $sizeId);

        if (!$productSize) {
            return response()->json(['productSizeId' => null]);
        }
        return response()->json(['productSizeId' => $productSize->id]);
    }

    public function modify(
        ProductAddRequest $request,
        Product $product,
        int $sizeId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productSizeService->modify(
                $product,
                $sizeId,
                $request->validated(),
            );
            DB::commit();
            return response()->json(['message' => 'Size modified.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function remove(
        Product $product,
        int $sizeId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productSizeService->remove(
                $product,
                $sizeId,
            );
            DB::commit();
            return response()->json(['message' => 'Size removed.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    private function getId(int $productId, int $sizeId): ProductSize|null
    {
        return ProductSize::where('product_id', '=', $productId)
            ->where('size_id', '=', $sizeId)
            ->first();
    }
}
