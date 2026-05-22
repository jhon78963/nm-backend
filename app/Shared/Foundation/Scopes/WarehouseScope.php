<?php

namespace App\Shared\Foundation\Scopes;

use App\Shared\Foundation\Support\AuthenticatedUserWarehouseResolver;
use App\Shared\Foundation\Support\WarehouseQueryFilter;
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

        WarehouseQueryFilter::apply($builder, $model->getTable().'.warehouse_id');
    }
}
