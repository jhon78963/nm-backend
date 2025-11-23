<?php

namespace App\Directory\Customer\Services;

use App\Directory\Customer\Models\Customer;
use App\Shared\Foundation\Services\ModelService;

class CustomerService extends ModelService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }
}
