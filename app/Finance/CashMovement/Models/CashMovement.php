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

    /**
     * Egresos desde la Cuenta Acumulada (fondo/ahorro).
     * NO afectan reportes operativos del mes ni «Ventas Mensuales por Método de Pago».
     */
    public const CATEGORY_ACCUMULATED = 'ACCUMULATED';

    public $timestamps = false;

    protected $table = 'cash_movements';

    protected $fillable = [
        'date',
        'accounting_month',
        'payroll_period',
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
        'accumulated_balance_applied',
        'warehouse_id',
        'creation_time',
        'creator_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'creation_time' => 'datetime',
        'date' => 'datetime',
        'accumulated_balance_applied' => 'boolean',
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

    public function scopeAccumulatedExpenses(Builder $query): Builder
    {
        return $query
            ->expenses()
            ->where('category', self::CATEGORY_ACCUMULATED);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CashMovementVoucher>
     */
    public function vouchers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashMovementVoucher::class)->orderBy('sort_order');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Purchase, CashMovement>
     */
    public function purchase(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function accountingPeriodLabel(): ?string
    {
        if ($this->accounting_month === null || $this->accounting_month === '') {
            return null;
        }

        try {
            $monthName = \Carbon\Carbon::createFromFormat('Y-m', $this->accounting_month)
                ->locale('es')
                ->translatedFormat('F Y');
        } catch (\Throwable) {
            $monthName = $this->accounting_month;
        }

        $quincena = match ($this->payroll_period) {
            'q1' => ' · cierre 1–15',
            'q2' => ' · cierre 16–fin',
            default => '',
        };

        return $monthName.$quincena;
    }
}
