<?php

namespace App\Product\Models;

use App\Gender\Models\Gender;
use App\Image\Models\Image;
use App\Product\Enums\ProductStatus;
use App\Size\Models\Size;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'purchase_price',
        'wholesale_price',
        'min_wholesale_price',
        'ratail_price',
        'min_ratail_price',
        'stock',
        'status',
        'gender_id'
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
            'wholesale_price' => 'float',
            'min_wholesale_price' => 'float',
            'ratail_price' => 'float',
            'min_ratail_price' => 'float',
        ];
    }

    public function gender(): BelongsTo {
        return $this->belongsTo(Gender::class);
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(
            Image::class,
            'product_image'
        );
    }

    // public function colors(): BelongsToMany {
    //     return $this->belongsToMany(
    //         Color::class,
    //         'product_color',
    //     )->withPivot(['price', 'stock']);
    // }

    public function sizes(): BelongsToMany {
        return $this->belongsToMany(
            Size::class,
            'product_size',
        )->withPivot(['price', 'stock']);
    }
}
