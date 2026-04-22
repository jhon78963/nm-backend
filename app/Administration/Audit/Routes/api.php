<?php

use App\Administration\Audit\Controllers\UserActionLogController;
use Illuminate\Support\Facades\Route;

Route::controller(UserActionLogController::class)->group(function (): void {
    Route::get('/user-action-logs', 'getAll');
});
