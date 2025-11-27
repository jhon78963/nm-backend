<?php

namespace App\Sales\Models;

use App\Administration\User\Models\User;
use App\Directory\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    // Desactivamos timestamps automáticos porque usas nombres personalizados y lógica en ModelService
    public $timestamps = false;

    protected $table = 'sales';

    protected $fillable = [
        'code',
        'customer_id',
        'total_amount',
        'tax_amount',
        'payment_method',
        'status',
        'notes',
        // Campos de auditoría (llenados por ModelService)
        'creator_user_id',
        'creation_time',
        'last_modifier_user_id',
        'last_modification_time',
        'is_deleted',
        'deleter_user_id',
        'deletion_time'
    ];

    protected $casts = [
        'creation_time' => 'datetime',
        'last_modification_time' => 'datetime',
        'deletion_time' => 'datetime',
        'total_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'is_deleted' => 'boolean'
    ];

    // --- RELACIONES ---

    public function details(): HasMany
    {
        return $this->hasMany(SaleDetail::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }
}
