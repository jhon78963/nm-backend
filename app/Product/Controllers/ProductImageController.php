<?php

namespace App\Product\Controllers;

use App\Product\Models\Product;
use App\Product\Services\ProductImageService;
use App\Shared\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use DB;

class ProductImageController extends Controller
{
    protected ProductImageService $productImageService;

    public function __construct(ProductImageService $productImageService)
    {
        $this->productImageService = $productImageService;
    }

    public function add(
        Product $product,
        int $imageId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productImageService->add(
                $product,
                $imageId,
            );
            DB::commit();
            return response()->json(['message' => 'Size added.'], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function remove(
        Product $product,
        int $imageId
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productImageService->remove(
                $product,
                $imageId,
            );
            DB::commit();
            return response()->json(['message' => 'Image removed.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }
}
