<?php

namespace App\Shared\Image\Controllers;

use App\Shared\Image\Models\Image;
use App\Shared\Image\Requests\ImageCreateRequest;
use App\Shared\Image\Resources\ImageResource;
use App\Shared\Image\Services\ImageService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Requests\GetAllRequest;
use App\Shared\Foundation\Resources\GetAllCollection;
use App\Shared\Foundation\Services\SharedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ImageController extends Controller
{
    public function __construct(
        protected ImageService $imageService,
        protected SharedService $sharedService
    ) {
    }

    public function create(ImageCreateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $this->sharedService->convertCamelToSnake($request->validated());

            $image = $this->imageService->create($data);

            return response()->json([
                'message' => 'Image created successfully.',
                'imageId' => $image->id,
            ], 201);
        });
    }

    public function delete(Image $image): JsonResponse
    {
        return DB::transaction(function () use ($image) {
            $this->imageService->validate($image, 'Image');

            $this->imageService->delete($image);

            return response()->json(['message' => 'Image deleted successfully.']);
        });
    }

    public function get(Image $image): JsonResponse
    {
        $this->imageService->validate($image, 'Image');

        return response()->json(new ImageResource($image));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query(
            $request,
            'Shared\\Image',
            'Image',
            ['id', 'type', 'path']
        );

        return response()->json(new GetAllCollection(
            ImageResource::collection($query['collection']),
            $query['total'],
            $query['pages']
        ));
    }
}
