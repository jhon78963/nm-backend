<?php

namespace App\Product\Controllers;

use App\Product\Models\ProductSize;
use App\Product\Requests\ProductAddRequest;
use App\Product\Services\ProductSizeColorService;
use App\Shared\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use DB;

class ProductSizeColorController extends Controller
{
    protected ProductSizeColorService $productSizeColorService;

    public function __construct(ProductSizeColorService $productSizeColorService)
    {
        $this->productSizeColorService = $productSizeColorService;
    }

    public function add(
        ProductAddRequest $request,
        ProductSize $productSize,
        int $colorId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productSizeColorService->add(
                $productSize,
                $colorId,
                $request->validated(),
            );
            DB::commit();
            return response()->json(['message' => 'Color added.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function modify(
        ProductAddRequest $request,
        ProductSize $productSize,
        int $colorId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productSizeColorService->modify(
                $productSize,
                $colorId,
                $request->validated(),
            );
            DB::commit();
            return response()->json(['message' => 'Color modified.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function remove(
        ProductSize $productSize,
        int $colorId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productSizeColorService->remove(
                $productSize,
                $colorId,
            );
            DB::commit();
            return response()->json(['message' => 'Color removed.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }
}
