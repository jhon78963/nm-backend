<?php

use App\Administration\Tenant\Controllers\SystemAdminTenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Admin – Rutas de Provisión SaaS
|--------------------------------------------------------------------------
| Prefijo:    /api/system-admin
| Middleware: auth:sanctum  (aplicado desde routes/api.php)
|             system.admin  (solo tenant_id === 1)
*/
Route::prefix('system-admin')
    ->middleware('system.admin')
    ->controller(SystemAdminTenantController::class)
    ->group(function (): void {
        Route::get('/tenants',              'index');
        Route::post('/tenants',             'store');
        Route::patch('/tenants/{id}/features', 'updateFeatures');
    });
