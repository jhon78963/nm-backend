<?php
namespace App\Shared\Image\Services;

use App\Shared\Image\Models\Image;
use App\Shared\Foundation\Services\ModelService;

class ImageService extends ModelService
{
    public function __construct(Image $image)
    {
        parent::__construct($image);
    }
}
