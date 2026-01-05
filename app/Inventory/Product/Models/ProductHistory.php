<?php

namespace App\Inventory\Product\Models;

use App\Administration\User\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProductHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'entity_type', // 'PRODUCT' (General), 'SIZE' (Talla), 'COLOR' (Variante)
        'entity_id',
        'event_type',  // 'CREATED', 'UPDATED', 'DELETED'
        'old_values',
        'new_values',
        'creation_time',
        'creator_user_id'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'creation_time' => 'datetime'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }
}
