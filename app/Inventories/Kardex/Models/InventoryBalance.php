<?php

namespace App\Inventories\Kardex\Models;

use App\Administrations\Tenants\Models\Tenant;
use App\Inventories\Colors\Models\Color;
use App\Inventories\Products\Models\Product;
use App\Inventories\Products\Models\ProductSize;
use App\Administrations\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'product_size_id',
        'color_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }
}
