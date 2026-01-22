<?php

namespace App\Finance\Expense\Services;

use App\Finance\Expense\Models\Expense;
use App\Shared\Foundation\Services\ModelService;

class ExpenseService extends ModelService
{
    public function __construct(Expense $expense)
    {
        parent::__construct($expense);
    }
}
