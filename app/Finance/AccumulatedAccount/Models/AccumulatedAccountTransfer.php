<?php

namespace App\Finance\AccumulatedAccount\Models;

use App\Shared\Foundation\Traits\BelongsToWarehouse;
use Illuminate\Database\Eloquent\Model;

class AccumulatedAccountTransfer extends Model
{
    use BelongsToWarehouse;

    public $timestamps = false;

    protected $table = 'accumulated_account_transfers';

    protected $fillable = [
        'transfer_month',
        'cash_amount',
        'digital_amount',
        'operational_cash_snapshot',
        'operational_digital_snapshot',
        'note',
        'creation_time',
        'creator_user_id',
        'warehouse_id',
    ];

    protected $casts = [
        'cash_amount' => 'decimal:2',
        'digital_amount' => 'decimal:2',
        'operational_cash_snapshot' => 'decimal:2',
        'operational_digital_snapshot' => 'decimal:2',
        'creation_time' => 'datetime',
    ];

    public function totalAmount(): float
    {
        return round((float) $this->cash_amount + (float) $this->digital_amount, 2);
    }
}
