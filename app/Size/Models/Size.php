<?php

namespace App\Size\Models;

use App\Color\Models\Color;
use App\Product\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Size extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'description',
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

    public function products(): BelongsToMany {
        return $this->belongsToMany(
            Product::class,
            'product_size',
        )->withPivot([
           'stock', 'purchase_price', 'sale_price', 'min_sale_price'
        ]);
    }

    public function colors(): BelongsToMany
    {
        return $this->belongsToMany(
            Color::class,
            'product_size_color',
            'product_size_id',
            'color_id'
        )->withPivot(['stock']);
    }

    public function sizeType(): BelongsTo {
        return $this->belongsTo(SizeType::class);
    }
}
