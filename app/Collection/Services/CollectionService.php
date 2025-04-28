<?php

namespace App\Collection\Services;

use App\Collection\Models\Collection;
use App\Shared\Services\ModelService;

class CollectionService
{
    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function create(array $newCollection): Collection
    {
        return $this->modelService->create(new Collection(), $newCollection);
    }

    public function delete(Collection $collection): void
    {
        $this->modelService->delete($collection);
    }

    public function update(Collection $collection, array $editCollection): void
    {
        $this->modelService->update($collection, $editCollection);
    }

    public function validate(Collection $collection, string $modelName): Collection
    {
        return $this->modelService->validate($collection, $modelName);
    }
}
