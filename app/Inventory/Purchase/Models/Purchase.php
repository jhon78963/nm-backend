<?php

namespace App\Inventory\Purchase\Models;

use App\Directory\Vendor\Models\Vendor;
use App\Inventory\Warehouse\Models\Warehouse;
use App\Inventory\Purchase\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $table = 'purchases';

    public $timestamps = false;

    protected $fillable = [
        'creator_user_id',
        'last_modification_time',
        'last_modifier_user_id',
        'deletion_time',
        'deleter_user_id',
        'is_deleted',
        'vendor_id',
        'supplier_name',
        'document_note',
        'registered_at',
        'warehouse_id',
        'currency',
        'status',
        'total_subtotal',
        'payload_json',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_user_id',
    ];

    protected $hidden = [
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'creation_time' => 'datetime',
            'registered_at' => 'date',
            'total_subtotal' => 'float',
            'status' => PurchaseStatus::class,
            'cancelled_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vendor, Purchase>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Warehouse, Purchase>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<PurchaseLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class, 'purchase_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseStatus::Cancelled;
    }
}
