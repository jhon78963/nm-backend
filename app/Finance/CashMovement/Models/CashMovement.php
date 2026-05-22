<?php

namespace App\Finance\CashMovement\Models;

use App\Shared\Foundation\Traits\BelongsToWarehouse;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use BelongsToWarehouse;

    public $timestamps = false;

    protected $table = 'cash_movements';

    protected $fillable = [
        'date',
        'type',
        'amount',
        'description',
        'payment_method',
        'category',
        'voucher_path',
        'warehouse_id',
        'creation_time',
        'creator_user_id',
        'is_deleted'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'creation_time' => 'datetime',
        'date' => 'datetime',
    ];
}
