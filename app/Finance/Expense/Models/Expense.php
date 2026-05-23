<?php

namespace App\Finance\Expense\Models;

use App\Administration\User\Models\User;
use App\Finance\CashMovement\Models\CashMovement;
use App\Shared\Foundation\Traits\BelongsToWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vista de gastos administrativos sobre cash_movements.
 * Toda escritura de gastos administrativos debe persistir en cash_movements.
 */
class Expense extends Model
{
    use BelongsToWarehouse;

    public $timestamps = false;

    protected $table = 'cash_movements';

    protected $fillable = [
        'date',
        'description',
        'expense_category',
        'amount',
        'payment_method',
        'reference_code',
        'creator_user_id',
        'warehouse_id',
    ];

    protected $casts = [
        'date' => 'datetime',
        'amount' => 'decimal:2',
        'is_deleted' => 'boolean',
        'creation_time' => 'datetime',
        'last_modification_time' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('administrative_expense', function (Builder $builder): void {
            $builder
                ->where('type', CashMovement::TYPE_EXPENSE)
                ->where('category', CashMovement::CATEGORY_ADMINISTRATIVE);
        });

        static::creating(function (Expense $expense): void {
            $expense->type = CashMovement::TYPE_EXPENSE;
            $expense->category = CashMovement::CATEGORY_ADMINISTRATIVE;
        });
    }

    public function getExpenseDateAttribute(): mixed
    {
        return $this->date;
    }

    public function setExpenseDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = $value;
    }

    public function getCategoryAttribute(): ?string
    {
        return $this->attributes['expense_category'] ?? null;
    }

    public function setCategoryAttribute(?string $value): void
    {
        $this->attributes['expense_category'] = $value;
    }

    public function getUserIdAttribute(): ?int
    {
        return $this->creator_user_id !== null ? (int) $this->creator_user_id : null;
    }

    public function setUserIdAttribute(?int $value): void
    {
        $this->creator_user_id = $value;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function scopeActive($query): mixed
    {
        return $query->where('is_deleted', false);
    }
}
