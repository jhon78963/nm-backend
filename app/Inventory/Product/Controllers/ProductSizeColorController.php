<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Product\Requests\ProductAddRequest;
use App\Inventory\Product\Services\ProductSizeColorService;
use App\Shared\Foundation\Controllers\Controller;
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
