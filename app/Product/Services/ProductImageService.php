<?php

namespace App\Product\Services;

use App\Product\Models\Product;
use App\Shared\Requests\FileMultipleUploadRequest;
use App\Shared\Services\FileService;
use App\Shared\Services\ModelService;
use Illuminate\Support\Facades\Http;
use DB;

class ProductImageService
{
    protected FileService $fileService;
    protected ModelService $modelService;

    public function __construct(FileService $fileService, ModelService $modelService)
    {
        $this->fileService = $fileService;
        $this->modelService = $modelService;
    }


    public function add(Product $product, string $path, string $size, string $name): void
    {
        $this->fileService->attach(
            $product,
            'images',
            $path,
            ['size' => $size, 'name' => $name]
        );
    }

    public function getAll(Product $product)
    {
        return DB::table('product_image')
            ->where('product_id', '=', $product->id)
            ->get();
    }


    public function remove(Product $product, string $path): void
    {
        $this->fileService->detach(
            $product,
            'images',
            $path,
        );
    }

    public function removeAll(Product $product, FileMultipleUploadRequest $request)
    {
        $images = $request->input('path');
        $token = config('zg.token');
        $url = config('zg.url');
        foreach($images as $path) {
            $response = Http::withToken($token)->delete("$url/images/$path");
            if ($response->ok()) {
                $this->fileService->detach(
                    $product,
                    'images',
                    $path,
                );
            }
        }
    }
}
