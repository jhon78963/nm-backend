<?php

namespace App\Product\Controllers;

use App\Product\Models\Product;
use App\Product\Requests\ProductAddRequest;
use App\Product\Services\ProductColorService;
use App\Shared\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use DB;

class ProductColorController extends Controller
{
    protected ProductColorService $productColorService;

    public function __construct(ProductColorService $productColorService)
    {
        $this->productColorService = $productColorService;
    }

    public function add(
        ProductAddRequest $request,
        Product $product,
        int $colorId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productColorService->add(
                $product,
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
        Product $product,
        int $colorId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productColorService->modify(
                $product,
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
        Product $product,
        int $colorId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productColorService->remove(
                $product,
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
