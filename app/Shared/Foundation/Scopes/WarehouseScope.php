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

        $warehouseId = (int) ($user->warehouse_id ?? 0);

        if ($warehouseId > 0) {
            $builder->where($model->getTable().'.warehouse_id', $warehouseId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
