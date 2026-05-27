<?php

namespace App\Finance\CashMovement\Models;

use App\Inventory\Purchase\Models\Purchase;
use App\Shared\Foundation\Traits\BelongsToWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use BelongsToWarehouse;

    public const TYPE_INCOME = 'INCOME';

    public const TYPE_EXPENSE = 'EXPENSE';

    public const CATEGORY_ADMINISTRATIVE = 'ADMINISTRATIVE';

    public const CATEGORY_STORE = 'STORE';

    /**
     * Compras de mercadería: intercambio de activos (caja → inventario).
     * NO se deduce en el P&L como gasto operativo; ya impacta en COGS cuando se vende.
     */
    public const CATEGORY_INVENTORY_PURCHASE = 'INVENTORY_PURCHASE';

    public $timestamps = false;

    protected $table = 'cash_movements';

    protected $fillable = [
        'date',
        'type',
        'amount',
        'description',
        'payment_method',
        'category',
        'expense_category',
        'reference_code',
        'voucher_path',
        'legacy_expense_id',
        'purchase_id',
        'warehouse_id',
        'creation_time',
        'creator_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'creation_time' => 'datetime',
        'date' => 'datetime',
    ];

    public function scopeExpenses(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_EXPENSE);
    }

    public function scopeOperatingExpenses(Builder $query): Builder
    {
        return $query
            ->expenses()
            ->whereIn('category', [self::CATEGORY_ADMINISTRATIVE, self::CATEGORY_STORE]);
    }

    public function scopeAdministrativeExpenses(Builder $query): Builder
    {
        return $query
            ->expenses()
            ->where('category', self::CATEGORY_ADMINISTRATIVE);
    }

    public function scopeStoreMovements(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_STORE);
    }

    public function scopeInventoryPurchases(Builder $query): Builder
    {
        return $query
            ->expenses()
            ->where('category', self::CATEGORY_INVENTORY_PURCHASE);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Purchase, CashMovement>
     */
    public function purchase(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
