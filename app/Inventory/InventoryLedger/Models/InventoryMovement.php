<?php

namespace App\Inventory\InventoryLedger\Models;

use App\Administration\Tenant\Models\Tenant;
use App\Administration\User\Models\User;
use App\Inventory\Color\Models\Color;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_size_id',
        'color_id',
        'direction',
        'quantity',
        'movement_type',
        'reference_type',
        'reference_id',
        'balance_after_movement',
        'occurred_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => InventoryMovementDirection::class,
            'movement_type' => InventoryMovementType::class,
            'quantity' => 'integer',
            'balance_after_movement' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    /**
     * Resuelve la referencia polimórfica sin instanciar clases no-Model (p. ej. controladores legacy en BD).
     */
    public function resolveReferenceModel(): ?Model
    {
        $type = $this->reference_type;
        $id = $this->reference_id;

        if ($type === null || $id === null) {
            return null;
        }

        if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return null;
        }

        /** @var Model|null */
        return $type::query()->find($id);
    }

    /**
     * Limpia referencias morph inválidas guardadas por error (p. ej. FQCN de un Controller).
     */
    public static function sanitizeInvalidReferenceTypes(): int
    {
        return static::query()
            ->whereNotNull('reference_type')
            ->where(function ($query): void {
                $query->where('reference_type', 'like', '%Controller%')
                    ->orWhere('reference_type', 'like', '%\\\\Requests\\\\%');
            })
            ->update([
                'reference_type' => null,
                'reference_id' => null,
            ]);
    }
}
