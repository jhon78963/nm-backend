<?php

namespace App\Inventory\InventoryLedger\Enums;

enum InventoryMovementType: string
{
    case Sale = 'SALE';
    case Purchase = 'PURCHASE';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
    case Reconciliation = 'RECONCILIATION';
    case Transfer = 'TRANSFER';
}
