<?php

namespace App\Shared\Image\Models;

use App\Inventory\Product\Models\Product;
use App\Inventory\Product\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Image extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'path'
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


    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_image',
            'product_id',
            'path',
            'id',
            'path',
        )->withPivot(['status', 'size', 'name']);
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'path', 'path');
    }

    /**
     * Resuelve los warehouse_id reales vinculados a la imagen vía product_image → products.
     *
     * @return list<int>
     */
    public function resolveWarehouseIds(): array
    {
        $paths = array_values(array_unique(array_filter([
            $this->path,
            ltrim((string) $this->path, '/'),
            '/'.ltrim((string) $this->path, '/'),
        ])));

        if ($paths === []) {
            return [];
        }

        return Product::query()
            ->withoutGlobalScopes()
            ->where('is_deleted', false)
            ->whereIn('id', function ($query) use ($paths): void {
                $query->select('product_id')
                    ->from('product_image')
                    ->whereIn('path', $paths);
            })
            ->pluck('warehouse_id')
            ->map(static fn ($warehouseId): int => (int) $warehouseId)
            ->filter(static fn (int $warehouseId): bool => $warehouseId > 0)
            ->unique()
            ->values()
            ->all();
    }
}
