<?php

namespace App\Inventory\InventoryLedger\Enums;

enum InventoryMovementDirection: string
{
    case In = 'IN';
    case Out = 'OUT';
}
