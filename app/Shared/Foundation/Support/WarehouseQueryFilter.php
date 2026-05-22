<?php

namespace App\Shared\Foundation\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class WarehouseQueryFilter
{
    public static function bypassScope(): bool
    {
        if (! auth()->check()) {
            return true;
        }

        $user = auth()->user();

        return method_exists($user, 'hasRole') && $user->hasRole('Super Admin');
    }

    public static function resolveWarehouseId(): int
    {
        if (self::bypassScope()) {
            return 0;
        }

        return (int) (auth()->user()->warehouse_id ?? 0);
    }

    public static function apply(EloquentBuilder|QueryBuilder $builder, string $column): void
    {
        if (self::bypassScope()) {
            return;
        }

        $warehouseId = self::resolveWarehouseId();

        if ($warehouseId > 0) {
            $builder->where($column, $warehouseId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
