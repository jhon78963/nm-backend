<?php

namespace App\Shared\Foundation\Services;

use App\Administration\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Auth;
use DB;

class ModelService
{
    public function attach(
        Model $model,
        string $relation,
        int $id,
        ?array $attributes = [],
    ): void {
        $model->$relation()->syncWithoutDetaching([$id => $attributes]);
    }

    public function create(Model $model, array $data): Model
    {
        $this->setCreationAuditFields($model);
        $model->fill($data);
        $model->save();
        return $model;
    }

    public function detach(Model $model, string $relation, int $id): void
    {
        $model->$relation()->detach($id);
    }

    public function delete(Model $model): void
    {
        $this->setDeleteAuditFields($model);
        $model->save();
    }

    public function get(Model $model, string $column, string|int $data): ?Model
    {
        return $model->where($column, '=', $data)->first();
    }

    public function getAllRelation(Model $model, string $relation)
    {
        return $model->$relation()->orderBy('id', 'desc')->get();
    }

    public function mergeModels(array $models): Collection
    {
        $collection = collect();
        foreach ($models as $model) {
            if ($model instanceof Model) {
                $records = $model->where('is_deleted', '=', false)->get();
                $collection = $collection->merge($records);
            }
        }
        return $collection;
    }

    public function update(Model $model, array $data): Model
    {
        $this->setUpdateAuditFields($model);
        $model->fill($data);
        $model->save();
        return $model;
    }

    public function validate(User | Model $model, string $modelName): User | Model
    {
        if ($model->is_deleted == true) {
            throw new ModelNotFoundException("$modelName does not exists.");
        }

        return $model;
    }

    public function validatePivote(
        string $tableName,
        string $firstIdName,
        string $secondIdName,
        int $firstId,
        int $secondId,
    ): bool  {
        return DB::table($tableName)
            ->where($firstIdName, '=', $firstId)
            ->where($secondIdName, '=', $secondId)
            ->exists();
    }

    public function getPivoteId(
        string $modelClass,   // Ej: App\Models\UserRole
        string $firstIdName,
        string $secondIdName,
        int $firstId,
        int $secondId
    ): ?Model {
        return $modelClass::where($firstIdName, '=', $firstId)
            ->where($secondIdName, '=', $secondId)
            ->first();
    }

    private static function setCreationAuditFields(Model $model): void
    {
        $model->creator_user_id = Auth::id();
    }

    private static function setDeleteAuditFields(Model $model): void
    {
        $model->is_deleted = true;
        $model->deleter_user_id = Auth::id();
        $model->deletion_time = now()->format('Y-m-d H:i:s');
    }

    private static function setUpdateAuditFields(Model $model): void
    {
        $model->last_modifier_user_id = Auth::id();
        $model->last_modification_time = now()->format('Y-m-d H:i:s');
    }
}
