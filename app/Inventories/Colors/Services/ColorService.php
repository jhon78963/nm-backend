<?php
namespace App\Inventories\Colors\Services;

use App\Inventories\Colors\Models\Color;
use App\Shared\Foundation\Services\ModelService;

class ColorService extends ModelService
{
    public function __construct(Color $color)
    {
        parent::__construct($color);
    }
}
