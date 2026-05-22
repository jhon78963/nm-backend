<?php

namespace App\Shared\Foundation\Traits;

use App\Inventory\Warehouse\Models\Warehouse;
use App\Shared\Foundation\Scopes\WarehouseScope;
use App\Shared\Foundation\Support\AuthenticatedUserWarehouseResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToWarehouse
{
    protected static function bootBelongsToWarehouse(): void
    {
        static::addGlobalScope(new WarehouseScope);

        static::creating(function ($model): void {
            if (! empty($model->warehouse_id)) {
                return;
            }

            if (! auth()->check()) {
                return;
            }

            $warehouseId = AuthenticatedUserWarehouseResolver::resolve();

            if ($warehouseId > 0) {
                $model->warehouse_id = $warehouseId;
            }
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
