<?php
namespace App\Inventory\Color\Services;

use App\Inventory\Color\Models\Color;
use App\Shared\Foundation\Services\ModelService;

class ColorService extends ModelService
{
    public function __construct(Color $color)
    {
        parent::__construct($color);
    }
}
