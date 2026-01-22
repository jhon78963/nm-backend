<?php

use App\Finance\Expense\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::controller(ExpenseController::class)->group(function () {
    Route::post('/expenses', 'create');
    Route::patch('/expenses/{expense}', 'update');
    Route::delete('/expenses/{expense}', 'delete');
    Route::get('/expenses', 'getAll');
    Route::get('/expenses/{expense}', 'get');
});
