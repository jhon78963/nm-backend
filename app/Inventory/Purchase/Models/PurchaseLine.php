<?php

namespace App\Inventory\Purchase\Models;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Size\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseLine extends Model
{
    protected $table = 'purchase_lines';

    public $timestamps = false;

    protected $fillable = [
        'purchase_id',
        'line_id',
        'product_id',
        'size_id',
        'product_size_id',
        'barcode',
        'purchase_price',
        'sale_price',
        'min_sale_price',
        'subtotal',
        'size_stock_delta',
        'has_color_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'float',
            'sale_price' => 'float',
            'min_sale_price' => 'float',
            'subtotal' => 'float',
            'has_color_breakdown' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Purchase, PurchaseLine>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /**
     * @return BelongsTo<Product, PurchaseLine>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Size, PurchaseLine>
     */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /**
     * @return BelongsTo<ProductSize, PurchaseLine>
     */
    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    /**
     * @return HasMany<PurchaseLineColorDelta>
     */
    public function colorDeltas(): HasMany
    {
        return $this->hasMany(PurchaseLineColorDelta::class, 'purchase_line_id');
    }
}
