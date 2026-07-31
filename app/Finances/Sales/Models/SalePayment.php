<?php

namespace App\Finances\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    // Desactivamos timestamps automáticos porque la migración solo tiene created_at
    public $timestamps = false;

    protected $table = 'sale_payments';

    protected $fillable = [
        'sale_id',
        'method',     // CASH, YAPE, PLIN, CARD
        'amount',
        'reference',  // Nro Operación opcional
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Relación inversa con la Venta (Sale)
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
