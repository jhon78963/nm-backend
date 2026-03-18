<?php

namespace App\Finance\Order\Models;

use App\Inventory\Color\Models\Color;
use App\Inventory\Product\Models\Product;
use App\Inventory\Size\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDetail extends Model
{
    public $timestamps = false;

    protected $table = 'order_details';

    protected $fillable = [
        'order_id',
        'product_id',
        'size_id',
        'color_id',

        // Snapshots (Histórico)
        'product_name_snapshot',
        'size_name_snapshot',
        'color_name_snapshot',
        'sku_snapshot',

        // Valores numéricos
        'quantity',
        'purchase_price',
        'sale_price',
        'min_sale_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'float',
        'sale_price' => 'float',
        'min_sale_price' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class, 'color_id');
    }
}
