<?php

namespace App\Inventory\Product\Controllers;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Requests\ImageRequest;
use App\Inventory\Product\Requests\ImagesRequest;
use App\Inventory\Product\Services\ProductImageService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\FileMultipleUploadRequest;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
    public function __construct(
        protected ProductImageService $productImageService,
        protected SharedService $sharedService,
    ) {
    }

    public function add(Product $product, ImageRequest $request): JsonResponse
    {
        return DB::transaction(
            function () use ($product, $request): JsonResponse {
                $this->productImageService->add(
                    $product,
                    $request->image,
                    $request->size,
                    $request->name
                );

                return response()->json(
                    ['message' => 'Image uploaded successfully.'],
                    201,
                );
            }
        );
    }

    public function multipleAdd(Product $product, ImagesRequest $request): JsonResponse
    {
        return DB::transaction(
            function () use ($product, $request): JsonResponse {
                $images = $request->input('image', []);
                $sizes = $request->input('size', []);
                $names = $request->input('name', []);

                foreach ($images as $index => $path) {
                    $this->productImageService->add(
                        $product,
                        $path,
                        $sizes[$index] ?? null,
                        $names[$index] ?? null,
                    );
                }

                return response()->json(
                    ['message' => 'Images uploaded successfully.'],
                    201,
                );
            }
        );
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

    public function remove(Product $product, string $path): JsonResponse
    {
        return DB::transaction(
            callback: function () use ($product, $path): JsonResponse {
                $this->productImageService->remove(
                    $product,
                    $path
                );

                return response()->json(
                    ['message' => 'Image removed successfully.']
                );
            }
        );
    }

    public function multipleRemove(
        Product $product,
        FileMultipleUploadRequest $request,
    ): JsonResponse {
        return DB::transaction(
            callback: function () use ($product, $request): JsonResponse {
                $this->productImageService->removeAll(
                    $product,
                    $request,
                );

                return response()->json(
                    data: ['message' => 'Images removed successfully.']
                );
            }
        );
    }
}
