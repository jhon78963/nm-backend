<?php

namespace App\Inventory\InventoryLedger\DTOs;

use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use DateTimeInterface;

readonly class InventoryMovementDTO
{
    public function __construct(
        public int $tenantId,
        public int $warehouseId,
        public int $productSizeId,
        public ?int $colorId,
        public InventoryMovementDirection $direction,
        public int $quantity,
        public InventoryMovementType $movementType,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?int $createdByUserId = null,
    ) {}
}
