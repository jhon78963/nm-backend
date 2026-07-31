<?php

namespace App\Directories\Vendors\Services;

use App\Directories\Vendors\Models\Vendor;
use App\Shared\Foundation\Services\ModelService;

class VendorService extends ModelService
{
    public function __construct(Vendor $vendor)
    {
        parent::__construct($vendor);
    }
}
