<?php

namespace App\Shared\Foundation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

abstract class ModelService
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        $instance = $this->model->newInstance();
        $this->setCreationAuditFields($instance);
        $instance->fill($data);
        $instance->save();

        return $instance;
    }

    public function update(Model $model, array $data): Model
    {
        $this->setUpdateAuditFields($model);
        $model->fill($data);
        $model->save();

        return $model;
    }

    public function delete(Model $model): void
    {
        $this->setDeleteAuditFields($model);
        $model->save();
    }

    public function get(string $column, string|int $data): ?Model
    {
        return $this->model->where($column, '=', $data)->first();
    }

    public function getAll(): Collection
    {
        return $this->model->orderBy('id', 'desc')->get();
    }

    public function validate(Model $model, string $modelName): Model
    {
        if ($model->getAttribute('is_deleted') == true) {
            throw new ModelNotFoundException("$modelName does not exist.");
        }

        return $model;
    }


    public function attach(Model $model, string $relation, int $id, ?array $attributes = []): void
    {
        $model->$relation()->syncWithoutDetaching([$id => $attributes]);
    }

    public function detach(Model $model, string $relation, int $id): void
    {
        $model->$relation()->detach($id);
    }

    protected function setCreationAuditFields(Model $model): void
    {
        $model->creator_user_id = Auth::id();
    }

    protected function setUpdateAuditFields(Model $model): void
    {
        $model->last_modifier_user_id = Auth::id();
        $model->last_modification_time = now()->format('Y-m-d H:i:s');
    }

    protected function setDeleteAuditFields(Model $model): void
    {
        $model->is_deleted = true;
        $model->deleter_user_id = Auth::id();
        $model->deletion_time = now()->format('Y-m-d H:i:s');
    }
}
