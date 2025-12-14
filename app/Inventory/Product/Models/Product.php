<?php

namespace App\Inventory\Product\Models;

use App\Inventory\Gender\Models\Gender;
use App\Inventory\Warehouse\Model\Warehouse;
use App\Shared\Image\Models\Image;
use App\Inventory\Product\Enums\ProductStatus;
use App\Inventory\Size\Models\Size;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'name',
        'description',
        'barcode',
        'percentage_discount',
        'cash_discount',
        'status',
        'gender_id',
        'warehouse_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'creator_user_id',
        'last_modification_time',
        'last_modifier_user_id',
        'is_deleted',
        'deleter_user_id',
        'deletion_time',
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
            'status' => ProductStatus::class,
            'purchase_price' => 'float',
            'sale_price' => 'float',
            'min_sale_price' => 'float',
        ];
    }

    /**
     * Get the gender associated with the product.
     *
     * @return BelongsTo
     */
    public function gender(): BelongsTo {
        return $this->belongsTo(Gender::class);
    }

    /**
     * Get the warehouse associated with the product.
     *
     * @return BelongsTo
     */
    public function warehouse(): BelongsTo {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the images associated with the product.
     *
     * @return BelongsToMany
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(
            Image::class,
            'product_image',
            'product_id',
            'path',
            'id',
            'id',
        )->withPivot(['status', 'size', 'name']);
    }

    /**
     * Get the sizes associated with the product.
     *
     * @return BelongsToMany
     */
    public function sizes(): BelongsToMany {
        return $this->belongsToMany(
            Size::class,
            'product_size',
        )->withPivot([
            'stock', 'purchase_price', 'sale_price', 'min_sale_price', 'barcode'
        ]);
    }

    /**
     * Get the productSizes associated with the product.
     *
     * @return HasMany
     */
    public function productSizes(): HasMany
    {
        return $this->hasMany(ProductSize::class, 'product_id');
    }
}
