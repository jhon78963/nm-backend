<?php

namespace App\Inventory\Purchase\Enums;

enum PurchaseStatus: string
{
    case Active = 'ACTIVE';
    case Cancelled = 'CANCELLED';
}
