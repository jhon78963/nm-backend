<?php

namespace App\Finance\CashMovement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovementVoucher extends Model
{
    public $timestamps = false;

    protected $table = 'cash_movement_vouchers';

    protected $fillable = [
        'cash_movement_id',
        'voucher_path',
        'sort_order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<CashMovement, CashMovementVoucher> */
    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }
}
