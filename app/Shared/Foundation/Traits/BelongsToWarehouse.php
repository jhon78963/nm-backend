<?php

namespace App\Shared\Foundation\Traits;

use App\Inventory\Warehouse\Models\Warehouse;
use App\Shared\Foundation\Scopes\WarehouseScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToWarehouse
{
    protected static function bootBelongsToWarehouse(): void
    {
        static::addGlobalScope(new WarehouseScope);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
