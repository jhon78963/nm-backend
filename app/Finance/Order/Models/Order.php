<?php

namespace App\Finance\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public $timestamps = false;

    protected $table = 'orders';

    protected $fillable = [
        'reference_date',
        'code',
        'total_amount',
        'origin_warehouse_id',
        'destination_warehouse_id',
        'tracking_number',
        'type',
        'status',
        'notes',
    ];

    protected $hidden = [
        'creator_user_id',
        'last_modification_time',
        'last_modifier_user_id',
        'is_deleted',
        'deleter_user_id',
        'deletion_time',
    ];

    protected $casts = [
        'creation_time' => 'datetime',
        'last_modification_time' => 'datetime',
        'deletion_time' => 'datetime',
        'total_amount' => 'decimal:2',
        'is_deleted' => 'boolean'
    ];

    public function details(): HasMany
    {
        return $this->hasMany(OrderDetail::class, 'sale_id');
    }
}
