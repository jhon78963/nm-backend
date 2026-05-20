<?php

use App\Finance\Expense\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::controller(ExpenseController::class)->group(function (): void {
    Route::post('/expenses', 'create')->middleware('permission:expense.create');
    Route::patch('/expenses/{expense}', 'update')->middleware('permission:expense.update');
    Route::delete('/expenses/{expense}', 'delete')->middleware('permission:expense.delete');
    Route::get('/expenses', 'getAll')->middleware('permission:expense.getAll');
    Route::get('/expenses/{expense}', 'get')->middleware('permission:expense.get');
});
