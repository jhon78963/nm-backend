<?php

namespace App\Inventories\Kardex\Enums;

enum InventoryMovementDirection: string
{
    case In = 'IN';
    case Out = 'OUT';
}
