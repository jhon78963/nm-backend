<?php

namespace App\Shared\Services;

use Illuminate\Database\Eloquent\Model;


class FileService
{
    public function attach(
        Model $model,
        string $relation,
        string $path,
        ?array $attributes = [],
    ): void {
        $model->$relation()->syncWithoutDetaching([$path => $attributes]);
    }

    public function detach(Model $model, string $relation, string $path): void
    {
        $model->$relation()->detach($path);
    }
}
