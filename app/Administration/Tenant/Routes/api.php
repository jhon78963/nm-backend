<?php

use App\Administration\Tenant\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:Super Admin'])->group(function (): void {
    Route::controller(TenantController::class)->group(function (): void {
        Route::post('/tenants', 'create');
        Route::patch('/tenants/{tenant}', 'update');
        Route::delete('/tenants/{tenant}', 'delete');
        Route::get('/tenants', 'getAll');
        Route::get('/tenants/{tenant}', 'get');
    });
});
