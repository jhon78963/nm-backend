<?php
namespace App\Color\Services;

use App\Color\Models\Color;
use App\Shared\Services\ModelService;

class ColorService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function create(array $newColor): void
    {
        $this->modelService->create(new Color(), $newColor);
    }

    public function delete(Color $color): void
    {
        $this->modelService->delete($color);
    }

    public function update(Color $color, array $editColor): void
    {
        $this->modelService->update($color, $editColor);
    }

    public function validate(Color $color, string $modelName): Color
    {
        return $this->modelService->validate($color, $modelName);
    }
}
