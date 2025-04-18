<?php
namespace App\Image\Services;

use App\Image\Models\Image;
use App\Shared\Services\ModelService;

class ImageService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function create(array $newImage): Image
    {
        return $this->modelService->create(new Image(), $newImage);
    }

    public function delete(Image $image): void
    {
        $this->modelService->delete($image);
    }

    public function validate(Image $image, string $modelName): Image
    {
        return $this->modelService->validate($image, $modelName);
    }
}
