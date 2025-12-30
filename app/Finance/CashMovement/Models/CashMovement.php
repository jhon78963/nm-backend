<?php

namespace App\Finance\CashMovement\Models;

use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    public $timestamps = false;

    protected $table = 'cash_movements';

    protected $fillable = [
        'type',
        'amount',
        'description',
        'payment_method',
        'creation_time',
        'creator_user_id',
        'is_deleted'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'creation_time' => 'datetime'
    ];
}
