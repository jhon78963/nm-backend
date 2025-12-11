<?php

namespace App\Inventory\Warehouse\Services;

use App\Inventory\Warehouse\Model\Warehouse;
use App\Shared\Foundation\Services\ModelService;

class WarehouseService extends ModelService
{
    public function __construct(Warehouse $warehouse)
    {
        parent::__construct($warehouse);
    }
}
