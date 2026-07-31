<?php
namespace App\Inventories\Genders\Services;

use App\Inventories\Genders\Models\Gender;
use App\Shared\Foundation\Services\ModelService;

class GenderService extends ModelService
{
    public function __construct(Gender $gender)
    {
        parent::__construct($gender);
    }
}
