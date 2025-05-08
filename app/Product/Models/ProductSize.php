<?php

namespace App\Product\Models;

use App\Color\Models\Color;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'stock',
        'price',
        'barcode',
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
            'stock' => 'float',
            'price' => 'int',
        ];
    }

    public function productSizeColors(): BelongsToMany {
        return $this->belongsToMany(
            Color::class,
            'product_size_color',
        )->withPivot(['price', 'stock']);
    }
}
