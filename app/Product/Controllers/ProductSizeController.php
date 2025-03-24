<?php

namespace App\Product\Controllers;

use App\Product\Models\Product;
use App\Product\Requests\ProductAddRequest;
use App\Product\Services\ProductSizeService;
use App\Shared\Controllers\Controller;
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
}
