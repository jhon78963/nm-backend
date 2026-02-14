<?php

use App\Directory\Team\Controllers\AttendanceController;
use App\Directory\Team\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::controller(TeamController::class)->group(function () {
    Route::post('/teams', 'create');
    Route::patch('/teams/{team}', 'update');
    Route::delete('/teams/{team}', 'delete');
    Route::get('/teams', 'getAll');
    Route::get('/teams/{team}', 'get');
});

Route::get('/attendance/{teamId}', [AttendanceController::class, 'getByMonth']);
Route::post('/attendance', [AttendanceController::class, 'store']);
