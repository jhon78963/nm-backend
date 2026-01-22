<?php

namespace App\Finance\Expense\Models;

use App\Administration\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public $timestamps = false;
    protected $table = 'expenses';

    protected $fillable = [
        'expense_date',
        'description',
        'category',
        'amount',
        'payment_method',
        'reference_code',
        'user_id',
        'creator_user_id',
    ];

    protected $casts = [
        'expense_date' => 'datetime',
        'amount' => 'decimal:2',
        'is_deleted' => 'boolean',
        'creation_time' => 'datetime',
        'last_modification_time' => 'datetime',
    ];

    /**
     * Relación: Un gasto es registrado por un usuario.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope para filtrar gastos activos (no eliminados).
     * Uso: Expense::active()->get();
     */
    public function scopeActive($query): mixed
    {
        return $query->where('is_deleted', false);
    }
}
