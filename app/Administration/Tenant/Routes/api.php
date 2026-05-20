<?php

use App\Administration\Tenant\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::controller(TenantController::class)->group(function (): void {
    Route::post('/tenants', 'create')->middleware('permission:tenant.create');
    Route::patch('/tenants/{tenant}', 'update')->middleware('permission:tenant.update');
    Route::delete('/tenants/{tenant}', 'delete')->middleware('permission:tenant.delete');
    Route::get('/tenants', 'getAll')->middleware('permission:tenant.getAll');
    Route::get('/tenants/{tenant}', 'get')->middleware('permission:tenant.get');
});
