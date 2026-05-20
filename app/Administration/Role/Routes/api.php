<?php

use App\Administration\Role\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::controller(RoleController::class)->group(function (): void {
    Route::get('/roles/permissions', 'permissionsIndex')->middleware('permission:role.permissionsIndex');
    Route::post('/roles', 'create')->middleware('permission:role.create');
    Route::patch('/roles/{role}', 'update')->middleware('permission:role.update');
    Route::delete('/roles/{role}', 'delete')->middleware('permission:role.delete');
    Route::get('/roles', 'getAll')->middleware('permission:role.getAll');
    Route::get('/roles/{role}', 'get')->middleware('permission:role.get');
    Route::post('/roles/{role}/sync-permissions', 'syncPermissions')->middleware('permission:role.syncPermissions');
});
