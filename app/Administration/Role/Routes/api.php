<?php

use App\Administration\Role\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:Super Admin'])->group(function (): void {
    Route::controller(RoleController::class)->group(function (): void {
        Route::get('/roles/permissions', 'permissionsIndex');
        Route::post('/roles', 'create');
        Route::patch('/roles/{role}', 'update');
        Route::delete('/roles/{role}', 'delete');
        Route::get('/roles', 'getAll');
        Route::get('/roles/{role}', 'get');
        Route::post('/roles/{role}/sync-permissions', 'syncPermissions');
    });
});
