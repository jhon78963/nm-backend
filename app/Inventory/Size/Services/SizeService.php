<?php
namespace App\Inventory\Size\Services;

use App\Inventory\Size\Models\Size;
use App\Shared\Foundation\Services\ModelService;

class SizeService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function create(array $newSize): Size
    {
        return $this->modelService->create(new Size(), $newSize);
    }

    public function delete(Size $size): void
    {
        $this->modelService->delete($size);
    }

    public function update(Size $size, array $editSize): void
    {
        $this->modelService->update($size, $editSize);
    }

    public function validate(Size $size, string $modelName): Size
    {
        return $this->modelService->validate($size, $modelName);
    }
}
