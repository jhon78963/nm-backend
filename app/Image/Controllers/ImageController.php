<?php

namespace App\Image\Controllers;

use App\Image\Models\Image;
use App\Image\Resources\ImageResource;
use App\Image\Services\ImageService;
use App\Shared\Controllers\Controller;
use App\Shared\Requests\GetAllRequest;
use App\Shared\Resources\GetAllCollection;
use App\Shared\Services\SharedService;
use Illuminate\Http\JsonResponse;

class ImageController extends Controller
{
    protected ImageService $imageService;
    protected SharedService $sharedService;

    public function __construct(ImageService $imageService, SharedService $sharedService)
    {
        $this->imageService = $imageService;
        $this->sharedService = $sharedService;
    }

    public function get(Image $image): JsonResponse
    {
        $imageValidated = $this->imageService->validate($image, 'Image');
        return response()->json(new ImageResource($imageValidated));
    }

    public function getAll(GetAllRequest $request): JsonResponse
    {
        $query = $this->sharedService->query($request, 'Image', 'Image', 'type');
        return response()->json(new GetAllCollection(
            ImageResource::collection($query['collection']),
            $query['total'],
            $query['pages'],
        ));
    }
}
