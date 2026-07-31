<?php

namespace App\Administrations\Warehouses\Services;

use App\Administrations\Warehouses\Models\Warehouse;
use App\Shared\Foundation\Services\ModelService;

class WarehouseService extends ModelService
{
    public function __construct(Warehouse $warehouse)
    {
        parent::__construct($warehouse);
    }
}
