<?php
namespace App\Inventory\Gender\Services;

use App\Inventory\Gender\Models\Gender;
use App\Shared\Foundation\Services\ModelService;

class GenderService extends ModelService
{
    public function __construct(Gender $gender)
    {
        parent::__construct($gender);
    }
}
