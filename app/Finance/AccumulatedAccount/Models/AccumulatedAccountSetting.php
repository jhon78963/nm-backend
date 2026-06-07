<?php

namespace App\Finance\AccumulatedAccount\Models;

use App\Shared\Foundation\Traits\BelongsToWarehouse;
use Illuminate\Database\Eloquent\Model;

class AccumulatedAccountSetting extends Model
{
    use BelongsToWarehouse;

    public $timestamps = false;

    protected $table = 'accumulated_account_settings';

    protected $fillable = [
        'cash_balance',
        'digital_balance',
        'initial_cash',
        'initial_digital',
        'is_initialized',
        'tracking_start_month',
        'warehouse_id',
        'creation_time',
        'creator_user_id',
        'last_modification_time',
        'last_modifier_user_id',
    ];

    protected $casts = [
        'cash_balance' => 'decimal:2',
        'digital_balance' => 'decimal:2',
        'initial_cash' => 'decimal:2',
        'initial_digital' => 'decimal:2',
        'is_initialized' => 'boolean',
        'creation_time' => 'datetime',
        'last_modification_time' => 'datetime',
    ];
}
