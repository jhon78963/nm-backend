<?php

use App\Administrations\ActionLogs\Controllers\UserActionLogController;
use Illuminate\Support\Facades\Route;

Route::controller(UserActionLogController::class)->group(function (): void {
    Route::get('/user-action-logs', 'getAll')->middleware('permission:audit.getAll');
});
