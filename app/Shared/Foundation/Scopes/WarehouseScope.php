<?php

namespace App\Shared\Foundation\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WarehouseScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        if (method_exists($user, 'hasRole') && $user->hasRole('Super Admin')) {
            return;
        }

        if ($user->warehouse_id) {
            $builder->where($model->getTable().'.warehouse_id', $user->warehouse_id);
        }
    }
}
