<?php

namespace App\Product\Controllers;

use App\Product\Models\Product;
use App\Product\Requests\ImageRequest;
use App\Product\Requests\ImagesRequest;
use App\Product\Services\ProductImageService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\FileMultipleUploadRequest;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;
use DB;

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
                $request->size,
                $request->name,
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
            $images = $request->input('image', []);
            $sizes = $request->input('size', []);
            $names = $request->input('name', []);

            foreach ($images as $index => $image) {
                $size = $sizes[$index] ?? null;
                $name = $names[$index] ?? null;
                $this->productImageService->add(
                    $product,
                    $image,
                    $size,
                    $name,
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
            'name' => $image->name,
            'path' => $image->path,
            'size' => $image->size,
            'status' => $image->status,
            'isDB' => true,
        ]);

        return response()->json($formatted);
    }

    public function remove(
        Product $product,
        string $path
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productImageService->remove(
                $product,
                $path,
            );
            DB::commit();
            return response()->json(['message' => 'Image removed.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function multipleRemove(
        Product $product,
        FileMultipleUploadRequest $request
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $this->productImageService->removeAll(
                $product,
                $request,
            );
            DB::commit();
            return response()->json(['message' => 'Images removed.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }
}
