<?php

namespace App\Shared\Services;

use App\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Auth;
use DB;

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
