<?php
namespace App\Inventory\Gender\Services;

use App\Inventory\Gender\Models\Gender;
use App\Shared\Foundation\Services\ModelService;

class GenderService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function validate(Gender $gender, string $modelName): Gender
    {
        return $this->modelService->validate($gender, $modelName);
    }
}
