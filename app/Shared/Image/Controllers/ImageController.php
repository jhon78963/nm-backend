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
use DB;

class ImageController extends Controller
{
    protected ImageService $imageService;
    protected SharedService $sharedService;

    public function __construct(ImageService $imageService, SharedService $sharedService)
    {
        $this->imageService = $imageService;
        $this->sharedService = $sharedService;
    }

    public function create(ImageCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newImage = $this->sharedService->convertCamelToSnake($request->validated());
            $image = $this->imageService->create($newImage);
            DB::commit();
            return response()->json([
                'message' => 'Image created.',
                'imageId' => $image->id,
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function delete(Image $image): JsonResponse
    {
        DB::beginTransaction();
        try {
            $imageValidated = $this->imageService->validate($image, 'Image');
            $this->imageService->delete($imageValidated);
            DB::commit();
            return response()->json(['message' => 'Image deleted.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' =>  $e->getMessage()]);
        }
    }

    public function get(Image $image): JsonResponse
    {
        $imageValidated = $this->imageService->validate($image, 'Image');
        return response()->json(new ImageResource($imageValidated));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query($request, 'Shared\\Image', 'Image', 'type');
        return response()->json(new GetAllCollection(
            ImageResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }
}
