<?php

namespace App\Inventory\Product\Models;

use App\Inventory\Color\Models\Color;
use App\Inventory\Size\Models\Size;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductSize extends Model
{
    use HasFactory;

    protected $table = "product_size";

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'product_id',
        'size_id',
        'barcode',
        'stock',
        'purchase_price',
        'sale_price',
        'min_sale_price',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'int',
            'purchase_price' => 'float',
            'sale_price' => 'float',
            'min_sale_price' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function productSizeColors(): BelongsToMany
    {
        return $this->belongsToMany(
            Color::class,
            'product_size_color',
        )->withPivot(['stock']);
    }

    public function colors()
    {
        return $this->belongsToMany(
            Color::class,
            'product_size_color',
            'product_size_id',
            'color_id'
        )->withPivot('stock');
    }
}
