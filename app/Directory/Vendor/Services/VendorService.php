<?php

namespace App\Directory\Vendor\Services;

use App\Directory\Vendor\Models\Vendor;
use App\Shared\Foundation\Services\ModelService;

class VendorService extends ModelService
{
    public function __construct(Vendor $vendor)
    {
        parent::__construct($vendor);
    }
}
