<?php

namespace App\Product\Controllers;

use App\Product\Models\Product;
use App\Product\Requests\ImageRequest;
use App\Product\Requests\ImagesRequest;
use App\Product\Services\ProductImageService;
use App\Shared\Controllers\Controller;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;
use DB;
use Storage;

class ProductImageController extends Controller
{
    protected ProductImageService $productImageService;
    protected SharedService $sharedService;

    public function __construct(
        ProductImageService $productImageService,
        SharedService $sharedService,
    ) {
        $this->productImageService = $productImageService;
        $this->sharedService = $sharedService;
    }

    public function add(Product $product, ImageRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $this->productImageService->add(
                $product,
                $request->image,
            );
            DB::commit();
            return response()->json(['message' => 'Image uploaded.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json($e->getMessage());
        }
    }
    public function multipleAdd(Product $product, ImagesRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            foreach ($request->input('image') as $image) {
                $this->productImageService->add(
                    $product,
                    $image,
                );
            }
            DB::commit();
            return response()->json(['message' => 'Images uploaded.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json($e->getMessage());
        }
    }
    public function getAll(Product $product)
    {
        $images = $this->productImageService->getAll($product);
        $formatted = collect($images)->map(fn($image): array => [
            'name' => $this->sharedService->getFileName($image->path),
            'path' => $this->sharedService->generateS3Url($image->path),
            'size' => Storage::disk('s3')->exists($image->path)
                        ? Storage::disk('s3')->size($image->path)
                        : null,
            'isDB' => true,
        ]);

        return response()->json($formatted);
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
