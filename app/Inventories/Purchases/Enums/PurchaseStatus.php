<?php

namespace App\Inventories\Purchases\Enums;

enum PurchaseStatus: string
{
    case Active = 'ACTIVE';
    case Cancelled = 'CANCELLED';
}
