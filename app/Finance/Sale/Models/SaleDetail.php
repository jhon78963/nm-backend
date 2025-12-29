<?php

namespace App\Finance\Sale\Models;

use App\Inventory\Color\Models\Color;
use App\Inventory\Product\Models\Product;
use App\Inventory\Size\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleDetail extends Model
{
    public $timestamps = false;

    protected $table = 'sale_details';

    protected $fillable = [
        'sale_id',
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
        'unit_price',
        'subtotal'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer'
    ];

    // --- RELACIONES ---

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
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
